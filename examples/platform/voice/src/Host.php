<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Voice;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref, SessionId, UtcInstant};
use AgenToolsPlatform\Core\Context\{ContextAccess, ContextBlock};
use AgenToolsPlatform\Core\Security\{BoundaryClock, Correlation, DisclosureMode, DisclosurePayload, DisclosurePolicy, ExecutionContext, HostIdentity};
use AgenToolsPlatform\Loqum\Ingress\{MediaState, Owner as IngressOwner, Request, Transcript};
use AgenToolsPlatform\Loqum\Delivery\{Attempt, DisplayReceipt, Lookup, Owner as DeliveryOwner, PlayoutReceipt, Response, Segment, UiCommand};
use AgenToolsPlatform\Postgres\Runtime\{Connection as C, ContextRevision};

/** Scripted external host/media owner with durable synthetic IO; never a real acoustic adapter. */
final class Host implements IngressOwner,DeliveryOwner,BoundaryClock,ContextAccess,ContextRevision
{
    /** @var array<string,mixed> */ public array $event=[];
    /** @var array<string,DisclosurePayload> */ public array $payloads=[];
    public bool $ttsOutage=false;
    public bool $loseReceipt=false;
    public bool $estimate=false;
    public bool $denyInspection=false;
    public function __construct(private Journey $j) {}
    /** @return array<string,mixed> */
    public function state(): array
    {
        return $this->j->db->row('SELECT * FROM '.$this->j->db->table('voice_demo_state').' WHERE tenant_id=:tenant',
            ['tenant'=>$this->j->tenant->value])??throw new ContractViolation(ErrorCode::AccessDenied);
    }
    public function revision(): int { return C::number($this->state(),'context_revision'); }
    public function now(): UtcInstant { return new UtcInstant(gmdate('Y-m-d\TH:i:s\Z')); }
    public function authorize(SessionId $session,string $purpose): HostIdentity
    {
        $this->j->db->assertIdle();$this->j->session->assertSame($session);$s=$this->state();
        if ($s['allowed']!==true || $purpose!=='voice.assist') { throw new ContractViolation(ErrorCode::AccessDenied); }
        return new HostIdentity($session->tenant,$this->j->ref('actor'),null,$this->j->ref('host:authorization'),C::text($s,'policy_revision'),
            $this->j->ref(C::text($s,'subject_ref')),$this->j->ref('consent:'.C::text($s,'policy_revision')),$this->j->ref('agent'));
    }
    public function request(string $handle): Request
    {
        if ($handle!=='host:event') { throw new ContractViolation(ErrorCode::AccessDenied); }
        $s=$this->state();$e=$this->event;$kind=self::string($e,'kind');$text=self::string($e,'text','Объясни пример.');
        $ctx=ExecutionContext::fromHost($this,$this->j->session,'voice.assist',new Correlation('voice-trace','voice-request'));
        $textHash=new PayloadHash('sha-256:'.hash('sha256',$text));
        $textRef=$this->j->ref('text:'.substr($this->j->hasher->hash($this->j->tenant,'voice-text-ref',[$ctx->scopeHash($this->j->hasher)->value,$textHash->value])->value,8));
        $this->j->vault->put($ctx,$textRef,new PayloadHash('sha-256:'.hash('sha256',json_encode(['text'=>$text],JSON_THROW_ON_ERROR))),json_encode(['text'=>$text],JSON_THROW_ON_ERROR));
        return new Request($this->j->session,$this->j->assistant,new Transcript(self::string($e,'event_id','transport:1'),
            $this->j->ref('realtime:'.self::integer($e,'media_epoch',C::number($s,'media_epoch'))),self::integer($e,'media_epoch',C::number($s,'media_epoch')),
            self::string($e,'utterance_id','ut-1'),self::integer($e,'revision',1),self::string($e,'locale',C::text($s,'locale')),
            new UtcInstant('2026-11-03T09:00:00Z'),new UtcInstant('2026-11-03T09:00:02Z'),$textRef,$textHash,
            self::integer($e,'context_revision',C::number($s,'context_revision')),self::string($e,'retry_key','transport:retry'),
            in_array($kind,['final','text'],true),$kind==='text'?'text-fallback':'voice'),
            'voice.assist',new Correlation('voice-trace','voice-request'),$this->j->ref('conversation:voice'));
    }
    public function media(ExecutionContext|Response $context): MediaState
    {
        $s=$this->state();return new MediaState($this->j->ref('realtime:'.C::number($s,'media_epoch')),C::number($s,'media_epoch'),C::number($s,'context_revision'));
    }
    public function assertPermitted(ExecutionContext $context,Request $request): void
    {
        $s=$this->state();
        if ($request->transcript->channel==='voice'?$s['recording']!==true:$s['text_allowed']!==true) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
    public function current(ExecutionContext $context,Ref $snapshot): int
    {
        $r=$this->j->db->row('SELECT revision,context_revision FROM '.$this->j->db->table('voice_demo_snapshots').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$context->session->tenant->value,'id'=>$snapshot->value]);
        return $r!==null && C::number($r,'context_revision')===$this->revision()?C::number($r,'revision'):0;
    }
    public function permits(ExecutionContext $context,ContextBlock $block): bool
    {
        $context->assertCurrent($this);return $block->purpose===$context->purpose && $block->ref->tenant->value===$this->j->tenant->value;
    }
    public function policy(Response $response): DisclosurePolicy { return $this->outputPolicy(); }
    public function outputPolicy(): DisclosurePolicy { return new DisclosurePolicy($this->j->ref('voice:policy'),'v1',DisclosureMode::CheckedSegments,false,65536); }
    public function assertCurrent(Response $response,string $operation): void
    {
        $this->j->db->assertIdle();$this->j->session->assertSame($response->context->session);$s=$this->state();
        if ($operation==='stop') { return; }
        $response->context->assertCurrent($this);
        if ($response->contextRevision!==$this->revision() || ($operation==='history' && $s['memory']!==true)
            || (in_array($operation,['speech','synthesize','play'],true) && $s['recording']!==true)
            || ($operation==='display' && $s['text_allowed']!==true)) { throw new ContractViolation(ErrorCode::AccessDenied); }
        if (!in_array($operation,['receipt','history','reconnect'],true)) {
            $stop=$this->j->db->row('SELECT id FROM '.$this->j->db->table('voice_demo_stops').' WHERE tenant_id=:tenant AND id=:id',
                ['tenant'=>$this->j->tenant->value,'id'=>$response->key->value]);
            if ($stop!==null) { throw new ContractViolation(ErrorCode::Cancelled); }
        }
    }
    public function authorizeStop(Response $response): void { $this->j->db->assertIdle();$this->j->session->assertSame($response->context->session); }
    public function payload(Segment $segment,string $channel): DisclosurePayload
    {
        $this->assertCurrent($segment->response,$channel==='speech'?'speech':'display');
        return $this->payloads[$segment->key->value]??throw new ContractViolation(ErrorCode::AccessDenied);
    }
    public function synthesize(Attempt $a): Ref
    {
        $this->assertCurrent($a->segment->response,'synthesize');$this->fence($a);
        if ($this->ttsOutage) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
        $db=$this->j->db;$artifact=$this->j->ref('audio:'.$a->key->value);
        $db->execute('INSERT INTO '.$db->table('voice_demo_media').' (tenant_id,id,hash,offset_bytes,artifact_ref) VALUES(:tenant,:id,:hash,:offset,:artifact) ON CONFLICT DO NOTHING',
            ['tenant'=>$a->key->tenant->value,'id'=>$a->key->value,'hash'=>$a->segment->hash->value,'offset'=>$a->offset,'artifact'=>$artifact->value]);
        $r=$this->mediaRow($a);
        if ($r===null || C::text($r,'hash')!==$a->segment->hash->value) { throw new IntegrityConflict('Media key payload conflict'); }return $artifact;
    }
    public function play(Attempt $a,Ref $artifact): ?PlayoutReceipt
    {
        $this->assertCurrent($a->segment->response,'play');$this->fence($a);$r=$this->mediaRow($a);
        if ($r===null || C::text($r,'hash')!==$a->segment->hash->value || C::text($r,'artifact_ref')!==$artifact->value) { throw new IntegrityConflict('Unbound media request'); }
        if ($r['receipt']===null) {
            $receipt=new PlayoutReceipt($this->j->ref('playout:'.$a->key->value),$a->segment->response->response,$a->segment->response->version,
                $a->segment->key,$a->key,$a->epoch,$a->segment->speakableBytes,'completed',$this->estimate?'server_estimate':'client_ack');
            $this->j->db->execute('UPDATE '.$this->j->db->table('voice_demo_media').' SET receipt=CAST(:receipt AS jsonb),physical_plays=physical_plays+1 WHERE tenant_id=:tenant AND id=:id AND receipt IS NULL',
                ['tenant'=>$a->key->tenant->value,'id'=>$a->key->value,'receipt'=>json_encode($receipt->toArray(),JSON_THROW_ON_ERROR)]);
        }
        if ($this->loseReceipt) { throw new \RuntimeException('Synthetic lost playout acknowledgement'); }
        return $this->status($a)->receipt;
    }
    public function status(Attempt $a): Lookup
    {
        $this->assertCurrent($a->segment->response,'receipt');$this->fence($a);$r=$this->mediaRow($a);
        if ($r===null || $r['receipt']===null) { return new Lookup('unknown'); }
        $d=json_decode(C::text($r,'receipt'),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($d)) { throw new IntegrityConflict('Malformed owner receipt'); }
        // Old immutable receipt is returned unchanged; ledger refuses it after an epoch change.
        return new Lookup('found',new PlayoutReceipt($this->j->ref(self::refId($d,'receipt_ref')),$this->j->ref(self::refId($d,'response_ref')),
            self::integer($d,'response_version'),$this->j->ref(self::refId($d,'segment_ref')),$this->j->ref(self::refId($d,'attempt_ref')),
            self::integer($d,'media_epoch'),self::integer($d,'delivered_offset'),self::string($d,'status'),self::string($d,'evidence_level')));
    }
    public function receipt(Segment $segment,string $handle): PlayoutReceipt { throw new ContractViolation(ErrorCode::AccessDenied); }
    public function display(Segment $s): DisplayReceipt
    {
        $this->assertCurrent($s->response,'display');$id=$this->j->ref('display:'.$s->key->value);$db=$this->j->db;
        $db->execute('INSERT INTO '.$db->table('voice_demo_display').' VALUES(:tenant,:id,:hash,:response,:receipt) ON CONFLICT DO NOTHING',
            ['tenant'=>$s->key->tenant->value,'id'=>$s->key->value,'hash'=>$s->hash->value,'response'=>$s->response->key->value,'receipt'=>$id->value]);
        $saved=$db->row('SELECT hash FROM '.$db->table('voice_demo_display').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$s->key->tenant->value,'id'=>$s->key->value]);
        if ($saved===null || C::text($saved,'hash')!==$s->hash->value) { throw new IntegrityConflict('Display key payload conflict'); }
        $db->execute('INSERT INTO '.$db->table('voice_demo_messages').' VALUES(:tenant,:id) ON CONFLICT DO NOTHING',['tenant'=>$s->key->tenant->value,'id'=>$s->response->key->value]);
        return new DisplayReceipt($id,$s->key,$this->media($s->response)->epoch,$s->displayBytes);
    }
    public function command(UiCommand $command): Ref
    {
        $s=$this->state();if ($command->contextRevision!==$this->revision() || new \DateTimeImmutable($command->expiresAt->value)<=new \DateTimeImmutable($this->now()->value) || $s['allowed']!==true) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $db=$this->j->db;$db->execute('INSERT INTO '.$db->table('voice_demo_commands').' VALUES(:tenant,:id,:hash) ON CONFLICT DO NOTHING',
            ['tenant'=>$command->id->tenant->value,'id'=>$command->id->value,'hash'=>$command->hash->value]);return $this->j->ref('ui-receipt:'.$command->id->value);
    }
    public function stop(Response $response,int $epoch): void
    {
        $this->authorizeStop($response);$this->j->db->execute('INSERT INTO '.$this->j->db->table('voice_demo_stops').' VALUES(:tenant,:id,:epoch) ON CONFLICT DO NOTHING',
            ['tenant'=>$response->key->tenant->value,'id'=>$response->key->value,'epoch'=>$epoch]);
    }
    public function revoke(Response $response): Ref
    {
        $this->j->db->execute('UPDATE '.$this->j->db->table('voice_demo_state').' SET recording=false,memory=false,export=false,retention_applied=true WHERE tenant_id=:tenant',
            ['tenant'=>$this->j->tenant->value]);return $this->j->ref('retention:'.$response->key->value);
    }
    public function operation(string $kind,string $id): void
    {
        if (!in_array($kind,['recording','memory','export'],true) || $this->state()[$kind]!==true) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $this->j->db->execute('INSERT INTO '.$this->j->db->table('voice_demo_operations').' VALUES(:tenant,:id,:kind) ON CONFLICT DO NOTHING',
            ['tenant'=>$this->j->tenant->value,'id'=>$id,'kind'=>$kind]);
    }
    private function fence(Attempt $a): void { if ($a->epoch!==C::number($this->state(),'media_epoch')) { throw new ContractViolation(ErrorCode::StaleContext); } }
    /** @return array<string,mixed>|null */
    private function mediaRow(Attempt $a): ?array { return $this->j->db->row('SELECT * FROM '.$this->j->db->table('voice_demo_media').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$a->key->tenant->value,'id'=>$a->key->value]); }
    /** @param array<array-key,mixed> $data */
    public static function string(array $data,string $key,?string $default=null): string
    {
        $v=$data[$key]??$default;if (!is_string($v)) { throw new \InvalidArgumentException('Expected string: '.$key); }return $v;
    }
    /** @param array<array-key,mixed> $data */
    public static function integer(array $data,string $key,?int $default=null): int
    {
        $v=$data[$key]??$default;if (!is_int($v)) { throw new \InvalidArgumentException('Expected integer: '.$key); }return $v;
    }
    /** @param array<array-key,mixed> $data */
    private static function refId(array $data,string $key): string
    {
        $v=$data[$key]??null;if (!is_array($v)) { throw new \InvalidArgumentException('Expected ref'); }return self::string($v,'id');
    }
}
