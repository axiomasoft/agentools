<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, IntegrityConflict, Ref};
use AgenToolsPlatform\Postgres\Runtime\Connection as C;

/** Technical persistence only. Host guards run outside these short, serialized SQL transactions. */
final readonly class Ledger
{
    public function __construct(private C $db, private CanonicalHasher $hasher) {}

    public function register(Segment $s,int $epoch): void
    {
        $r=$s->response;
        $this->db->transaction(function() use($r,$s,$epoch):void {
            $db=$this->db;$p=$this->params($r);
            $binding=$db->row('SELECT r.id FROM '.$db->table('runs').' r JOIN '.$db->table('turns').' t ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
                JOIN '.$db->table('sessions').' s ON s.tenant_id=t.tenant_id AND s.id=t.session_id
                WHERE r.tenant_id=:tenant AND r.id=:run AND t.id=:turn AND s.id=:session AND s.instance_id=:assistant',
                ['tenant'=>$p['tenant'],'run'=>$r->run->value,'turn'=>$r->turn->value,'session'=>$r->context->session->value,'assistant'=>$r->assistant->value]);
            if ($binding===null) { throw new ContractViolation(ErrorCode::AccessDenied); }
            $db->execute('INSERT INTO '.$db->table('voice_responses').' (tenant_id,id,hash,manifest,epoch)
                VALUES(:tenant,:response,:hash,CAST(:manifest AS jsonb),:epoch) ON CONFLICT DO NOTHING',
                $p+['hash'=>$r->hash->value,'manifest'=>self::json($r->manifest()),'epoch'=>$epoch]);
            $this->response($r,$epoch);
            $db->execute('INSERT INTO '.$db->table('voice_segments').' (tenant_id,id,response_id,sequence,hash,manifest,speech_bytes,display_bytes)
                VALUES(:tenant,:segment,:response,:seq,:hash,CAST(:manifest AS jsonb),:speech,:display) ON CONFLICT DO NOTHING',
                $p+['segment'=>$s->key->value,'seq'=>$s->sequence,'hash'=>$s->hash->value,'manifest'=>self::json($s->manifest()),'speech'=>$s->speakableBytes,'display'=>$s->displayBytes]);
            $this->segment($s);
        });
    }

    public function claim(Segment $s,int $epoch): ?Attempt
    {
        return $this->locked($s,$epoch,false,function(array $row) use($s,$epoch):?Attempt {
            if (C::number($row,'confirmed')===$s->speakableBytes) { return null; }
            if (in_array(C::text($row,'state'),['sending','unknown'],true)) { throw new ContractViolation(ErrorCode::DeliveryUnknown); }
            $earlier=$this->db->row('SELECT count(*) AS total,count(*) FILTER(WHERE confirmed<speech_bytes) AS pending FROM '.$this->db->table('voice_segments').'
                WHERE tenant_id=:tenant AND response_id=:response AND sequence<:seq',
                $this->params($s->response)+['seq'=>$s->sequence]);
            if ($earlier===null || C::number($earlier,'total')!==$s->sequence-1 || C::number($earlier,'pending')!==0) { throw new ContractViolation(ErrorCode::PolicyConflict); }
            $offset=C::number($row,'confirmed');
            $this->update($s,'state=\'sending\',artifact_ref=CASE WHEN attempt_offset=:offset THEN artifact_ref ELSE NULL END,attempt_offset=:offset', ['offset'=>$offset]);
            return new Attempt($s,$offset,$epoch,$this->hasher);
        });
    }

    public function synthesized(Attempt $a,Ref $artifact): void
    {
        $a->segment->key->tenant->assertSame($artifact->tenant);
        $this->locked($a->segment,$a->epoch,false,function(array $row) use($a,$artifact):void {
            $this->sending($a,$row);
            if ($row['artifact_ref']!==null && C::text($row,'artifact_ref')!==$artifact->value) { throw new IntegrityConflict('Synthesis key artifact conflict'); }
            $this->update($a->segment,'artifact_ref=:artifact,synthesized=true',['artifact'=>$artifact->value]);
        });
    }

    public function ready(Attempt $a): void
    {
        $this->locked($a->segment,$a->epoch,false,function(array $row) use($a):void { $this->sending($a,$row); });
    }

    public function assertActive(Segment $s,int $epoch): void
    {
        $this->locked($s,$epoch,false,static function(array $row):void {});
    }

    public function uncertain(Attempt $a,bool $beforePlayback=false): void
    {
        $this->locked($a->segment,$a->epoch,true,function(array $row) use($a,$beforePlayback):void {
            if (C::number($row,'attempt_offset')!==$a->offset) { throw new ContractViolation(ErrorCode::StaleClaim); }
            if (C::text($row,'state')==='sending') { $this->update($a->segment,'state=:state',['state'=>$beforePlayback?'failed':'unknown']); }
        });
    }

    public function record(Attempt $a,PlayoutReceipt $receipt): void
    {
        $s=$a->segment;$r=$s->response;
        $r->response->assertSame($receipt->response);$s->key->assertSame($receipt->segment);$a->key->assertSame($receipt->attempt);
        if ($receipt->responseVersion!==$r->version || $receipt->mediaEpoch!==$a->epoch
            || !in_array($receipt->offset,$s->boundaries,true) || $receipt->offset<$a->offset
            || ($receipt->status==='completed' && $receipt->offset!==$s->speakableBytes)) { throw new IntegrityConflict('Playout receipt binding conflict'); }
        $this->locked($s,$a->epoch,true,function(array $row) use($a,$receipt,$s):void {
            if (!$this->receipt($s,$receipt->ref,'speech',$receipt->toArray())) { return; }
            if (C::text($row,'state')==='pending' || $row['synthesized']!==true || $row['artifact_ref']===null
                || C::number($row,'attempt_offset')!==$a->offset || $receipt->offset<C::number($row,'confirmed')) { throw new IntegrityConflict('Playout offset/attempt conflict'); }
            $confirmed=$receipt->evidenceLevel==='client_ack'?$receipt->offset:C::number($row,'confirmed');
            $state=$receipt->evidenceLevel==='server_estimate'?'unknown':($receipt->status==='completed'?'delivered':$receipt->status);
            $this->update($s,'confirmed=:confirmed,estimated=:estimated,state=:state',
                ['confirmed'=>$confirmed,'estimated'=>max(C::number($row,'estimated'),$receipt->offset),'state'=>$state]);
        });
    }

    public function notFound(Attempt $a): void
    {
        $this->locked($a->segment,$a->epoch,true,function(array $row) use($a):void {
            if (!in_array(C::text($row,'state'),['sending','unknown'],true) || C::number($row,'attempt_offset')!==$a->offset
                || C::number($row,'confirmed')!==$a->offset) { throw new ContractViolation(ErrorCode::StaleClaim); }
            $this->update($a->segment,'state=\'failed\'');
        });
    }

    public function display(Segment $s,int $epoch,Ref $receipt,int $offset): void
    {
        if ($offset<0 || $offset>$s->displayBytes) { throw new \InvalidArgumentException('Bounded display offset required'); }
        $this->locked($s,$epoch,false,function(array $row) use($s,$receipt,$offset,$epoch):void {
            if (!$this->receipt($s,$receipt,'display',['segment'=>$s->key->value,'epoch'=>$epoch,'offset'=>$offset])) { return; }
            if ($offset<C::number($row,'displayed')) { throw new IntegrityConflict('Display regression'); }
            $this->update($s,'displayed=:offset,display_state=\'displayed\'',['offset'=>$offset]);
        });
    }

    public function displayUnknown(Segment $s,int $epoch): void
    {
        $this->locked($s,$epoch,false,function(array $row) use($s):void { $this->update($s,'display_state=\'unknown\''); });
    }

    public function reconnect(Response $r,int $epoch): void
    {
        $this->db->transaction(function() use($r,$epoch):void {
            $row=$this->response($r,null);
            if ($epoch<C::number($row,'epoch')) { throw new ContractViolation(ErrorCode::StaleContext); }
            $this->db->execute('UPDATE '.$this->db->table('voice_responses').' SET epoch=:epoch WHERE tenant_id=:tenant AND id=:response',
                $this->params($r)+['epoch'=>$epoch]);
        });
    }

    public function interrupt(Response $r,int $epoch,bool $revoked=false): void
    {
        $this->db->transaction(function() use($r,$epoch,$revoked):void {
            $row=$this->response($r,null,true);
            if ($epoch<C::number($row,'epoch')) { throw new ContractViolation(ErrorCode::StaleContext); }
            $p=$this->params($r);$db=$this->db;
            $db->execute('UPDATE '.$db->table('voice_responses').' SET stopped=true,epoch=:epoch'.($revoked?',revoked=true':'').' WHERE tenant_id=:tenant AND id=:response',$p+['epoch'=>$epoch]);
            $db->execute('UPDATE '.$db->table('voice_segments').' SET state=CASE WHEN state IN (\'sending\',\'unknown\') THEN \'unknown\'
                WHEN state=\'delivered\' THEN state ELSE \'interrupted\' END WHERE tenant_id=:tenant AND response_id=:response',$p);
            $db->execute('UPDATE '.$db->table('voice_commands').' SET state=CASE WHEN state=\'pending\' THEN \'revoked\' ELSE \'unknown\' END
                WHERE tenant_id=:tenant AND response_id=:response AND state IN (\'pending\',\'executing\')',$p);
        });
    }

    public function retained(Response $r,Ref $receipt): void
    {
        $r->response->tenant->assertSame($receipt->tenant);
        $this->db->transaction(function() use($r,$receipt):void {
            $row=$this->response($r,null,true);
            if ($row['revoked']!==true || ($row['retention_receipt_ref']!==null && C::text($row,'retention_receipt_ref')!==$receipt->value)) {
                throw new IntegrityConflict('Retention owner receipt conflict');
            }
            $this->db->execute('UPDATE '.$this->db->table('voice_responses').' SET retention_receipt_ref=:receipt WHERE tenant_id=:tenant AND id=:response',
                $this->params($r)+['receipt'=>$receipt->value]);
        });
    }

    /** @return array<string,mixed> */
    public function state(Segment $s): array { return $this->segment($s); }

    /**
     * Only the contiguous client-ack prefix, never synthesized/displayed/server-estimated bytes.
     * @return list<array<string,mixed>>
     */
    public function history(Response $r): array
    {
        if ($this->assertHash($r)['revoked']===true) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $rows=$this->db->execute('SELECT * FROM '.$this->db->table('voice_segments').' WHERE tenant_id=:tenant AND response_id=:response ORDER BY sequence',$this->params($r));
        $parts=[];$sequence=1;
        while (($row=$rows->fetch(\PDO::FETCH_ASSOC))!==false) {
            if (C::number($row,'sequence')!==$sequence++) { break; }
            $count=C::number($row,'confirmed');
            if ($count>0) {
                $manifest=json_decode(C::text($row,'manifest'),true,512,JSON_THROW_ON_ERROR);
                if (!is_array($manifest) || !isset($manifest['speakable_ref'])) { throw new IntegrityConflict('Missing speech content reference'); }
                $parts[]=['response_ref'=>$r->response->toArray(),'response_version'=>$r->version,'segment_ref'=>C::text($row,'id'),
                    'text_ref'=>$manifest['speakable_ref'],'through_bytes'=>$count,'evidence_level'=>'client_ack','understanding'=>null];
            }
            if ($count<C::number($row,'speech_bytes')) { break; }
        }
        return $parts;
    }

    public function queue(Segment $s,int $epoch,UiCommand $command): void
    {
        $s->key->assertSame($command->playbackAnchor);
        if ($command->contextRevision!==$s->response->contextRevision) { throw new ContractViolation(ErrorCode::StaleContext); }
        $this->locked($s,$epoch,false,function(array $row) use($s,$command):void {
            $p=$this->params($s->response)+['command'=>$command->id->value];$db=$this->db;
            $db->execute('INSERT INTO '.$db->table('voice_commands').' (tenant_id,id,response_id,hash,manifest)
                VALUES(:tenant,:command,:response,:hash,CAST(:manifest AS jsonb)) ON CONFLICT DO NOTHING',
                $p+['hash'=>$command->hash->value,'manifest'=>self::json($command->manifest())]);
            $saved=$db->row('SELECT hash,response_id FROM '.$db->table('voice_commands').' WHERE tenant_id=:tenant AND id=:command',
                ['tenant'=>$p['tenant'],'command'=>$p['command']]);
            if ($saved===null || C::text($saved,'hash')!==$command->hash->value || C::text($saved,'response_id')!==$s->response->key->value) { throw new IntegrityConflict('UI command identity conflict'); }
        });
    }

    /** Host executes a returned command using the same ID as its dedup key; unknown is never replayed blindly. */
    public function claimCommand(Segment $s,int $epoch,UiCommand $c): bool
    {
        return $this->locked($s,$epoch,false,function(array $row) use($s,$c):bool {
            $db=$this->db;$saved=$db->row('SELECT * FROM '.$db->table('voice_commands').' WHERE tenant_id=:tenant AND id=:command FOR UPDATE',
                ['tenant'=>$c->id->tenant->value,'command'=>$c->id->value]);
            if ($saved===null || C::text($saved,'hash')!==$c->hash->value || C::text($saved,'response_id')!==$s->response->key->value) { throw new IntegrityConflict('UI command binding conflict'); }
            if (C::text($saved,'state')==='executed') { return false; }
            if (C::text($saved,'state')!=='pending' || C::number($row,'confirmed')!==$s->speakableBytes) { throw new ContractViolation(ErrorCode::PolicyConflict); }
            $db->execute('UPDATE '.$db->table('voice_commands').' SET state=\'executing\' WHERE tenant_id=:tenant AND id=:command',
                ['tenant'=>$c->id->tenant->value,'command'=>$c->id->value]); return true;
        });
    }

    public function commandReceipt(UiCommand $c,Ref $receipt): void
    {
        $c->id->tenant->assertSame($receipt->tenant);
        $this->db->transaction(function() use($c,$receipt):void {
            $row=$this->db->row('SELECT * FROM '.$this->db->table('voice_commands').' WHERE tenant_id=:tenant AND id=:command FOR UPDATE',
                ['tenant'=>$c->id->tenant->value,'command'=>$c->id->value]);
            if ($row===null || C::text($row,'hash')!==$c->hash->value || !in_array(C::text($row,'state'),['executing','unknown','executed'],true)
                || ($row['receipt_ref']!==null && C::text($row,'receipt_ref')!==$receipt->value)) { throw new IntegrityConflict('UI execution receipt conflict'); }
            $this->db->execute('UPDATE '.$this->db->table('voice_commands').' SET state=\'executed\',receipt_ref=:receipt WHERE tenant_id=:tenant AND id=:command',
                ['tenant'=>$c->id->tenant->value,'command'=>$c->id->value,'receipt'=>$receipt->value]);
        });
    }

    /**
     * @template T
     * @param callable(array<string,mixed>):T $operation
     * @return T
     */
    private function locked(Segment $s,int $epoch,bool $stopped,callable $operation): mixed
    {
        return $this->db->transaction(function() use($s,$epoch,$stopped,$operation):mixed {
            $this->response($s->response,$epoch,$stopped);return $operation($this->segment($s));
        });
    }
    /** @return array<string,string> */
    private function params(Response $r): array { return ['tenant'=>$r->response->tenant->value,'response'=>$r->key->value]; }
    /** @return array<string,mixed> */
    private function response(Response $r,?int $epoch,bool $allowStopped=false): array
    {
        $row=$this->assertHash($r,true);
        if (($epoch!==null && C::number($row,'epoch')!==$epoch) || (!$allowStopped && $row['stopped']===true)) { throw new ContractViolation(ErrorCode::StaleContext); }
        return $row;
    }
    /** @return array<string,mixed> */
    private function assertHash(Response $r,bool $lock=false): array
    {
        $row=$this->db->row('SELECT * FROM '.$this->db->table('voice_responses').' WHERE tenant_id=:tenant AND id=:response'.($lock?' FOR UPDATE':''),$this->params($r));
        if ($row===null || C::text($row,'hash')!==$r->hash->value) { throw new IntegrityConflict('Response identity payload conflict'); }return $row;
    }
    /** @return array<string,mixed> */
    private function segment(Segment $s): array
    {
        $row=$this->db->row('SELECT * FROM '.$this->db->table('voice_segments').' WHERE tenant_id=:tenant AND id=:segment',
            ['tenant'=>$s->key->tenant->value,'segment'=>$s->key->value]);
        if ($row===null || C::text($row,'hash')!==$s->hash->value) { throw new IntegrityConflict('Segment identity payload conflict'); }return $row;
    }
    /** @param array<string,scalar|null> $values */
    private function update(Segment $s,string $set,array $values=[]): void
    {
        $this->db->execute('UPDATE '.$this->db->table('voice_segments').' SET '.$set.' WHERE tenant_id=:tenant AND id=:segment',
            $values+['tenant'=>$s->key->tenant->value,'segment'=>$s->key->value]);
    }
    /** @param array<string,mixed> $row */
    private function sending(Attempt $a,array $row): void
    {
        if (C::text($row,'state')!=='sending' || C::number($row,'attempt_offset')!==$a->offset) { throw new ContractViolation(ErrorCode::StaleClaim); }
    }
    /** @param array<string,mixed> $manifest */
    private function receipt(Segment $s,Ref $id,string $kind,array $manifest): bool
    {
        $s->key->tenant->assertSame($id->tenant);$db=$this->db;
        $hash=$this->hasher->hash($id->tenant,'voice-receipt',[$s->key->value,$kind,$manifest])->value;
        $saved=$db->row('INSERT INTO '.$db->table('voice_receipts').' (tenant_id,id,segment_id,hash,kind,manifest)
            VALUES(:tenant,:id,:segment,:hash,:kind,CAST(:manifest AS jsonb)) ON CONFLICT DO NOTHING RETURNING id',
            ['tenant'=>$id->tenant->value,'id'=>$id->value,'segment'=>$s->key->value,'hash'=>$hash,'kind'=>$kind,'manifest'=>self::json($manifest)]);
        if ($saved!==null) { return true; }
        $saved=$db->row('SELECT hash FROM '.$db->table('voice_receipts').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$id->tenant->value,'id'=>$id->value]);
        if ($saved===null || C::text($saved,'hash')!==$hash) { throw new IntegrityConflict('Receipt identity payload conflict'); }return false;
    }
    /** @param array<string,mixed> $value */
    private static function json(array $value): string { return json_encode($value,JSON_THROW_ON_ERROR); }
}
