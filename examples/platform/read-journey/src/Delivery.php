<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict};
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** One explicitly scripted native-key destination bridge; response generation is never retried. */
final readonly class Delivery
{
    public function __construct(private SupportJourney $journey) {}
    private function authorize(): void
    {
        $j=$this->journey;$j->idle();$j->context->assertCurrent($j->host);
        $row=$j->db->row('SELECT p.delivery_allowed,p.emergency,t.visible FROM '.$j->db->table('demo_permissions').' p JOIN '.$j->db->table('demo_tickets').' t ON t.tenant_id=p.tenant_id
            WHERE p.tenant_id=:tenant AND t.id=\'ST-17\'',['tenant'=>$j->tenant->value]);
        if($row===null || $row['delivery_allowed']!==true || $row['visible']!==true || $row['emergency']!==false){throw new ContractViolation(ErrorCode::AccessDenied);}
    }
    /** @return array<string, mixed> */
    private function intent(): array
    {
        $j=$this->journey;$this->authorize();
        return $j->db->row('SELECT d.*,r.payload_ref_id FROM '.$j->db->table('delivery_intents').' d JOIN '.$j->db->table('responses').' r ON r.tenant_id=d.tenant_id AND r.id=d.response_id
            JOIN '.$j->db->table('runs').' x ON x.tenant_id=d.tenant_id AND x.id=d.run_id JOIN '.$j->db->table('turns').' t ON t.tenant_id=x.tenant_id AND t.id=x.turn_id
            WHERE d.tenant_id=:tenant AND d.run_id=:run AND t.session_id=:session',
            ['tenant'=>$j->tenant->value,'run'=>$j->runId->value,'session'=>$j->session->value])??throw new ContractViolation(ErrorCode::AccessDenied);
    }
    /** Returns delivered or unknown, never infers delivered from completed Run. */
    public function deliver(): string
    {
        $j=$this->journey;$intent=$this->intent();$id=Connection::text($intent,'id');$hash=Connection::text($intent,'payload_hash');
        $json=$j->vault->get($j->context,$j->ref(Connection::text($intent,'payload_ref_id')));
        if(hash('sha256',$json)!==$hash){throw new IntegrityConflict('Delivery source hash conflict');}
        $data=json_decode($json,true,128,JSON_THROW_ON_ERROR);$response=is_array($data)?($data['response']??null):null;
        $parts=is_array($response)?($response['parts']??null):null;$display=[];
        if(!is_array($parts)){throw new IntegrityConflict('No persisted display response');}
        foreach($parts as $part){if(is_array($part) && ($part['kind']??null)==='text' && is_string($part['text']??null)){$display[]=$part['text'];}}
        $body=implode("\n",$display);if($body===''){throw new IntegrityConflict('Empty display response');}$bodyHash=hash('sha256',$body);
        $known=$this->destinationStatus($id,$hash,$bodyHash);
        if($known!==null){$this->record($id,$hash,'success',$known);return 'delivered';}
        $j->db->transaction(function()use($j,$id,$hash,$bodyHash):void{
            $row=$j->db->row('SELECT outcome FROM '.$j->db->table('delivery_intents').' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',['tenant'=>$j->tenant->value,'id'=>$id]);
            if($row===null){throw new ContractViolation(ErrorCode::AccessDenied);}
            $prior=$j->db->row('SELECT * FROM '.$j->db->table('demo_delivery_attempts').' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',['tenant'=>$j->tenant->value,'id'=>$id]);
            if($prior!==null && (Connection::text($prior,'payload_hash')!==$hash || Connection::text($prior,'body_hash')!==$bodyHash)){throw new IntegrityConflict('DELIVERY_PAYLOAD_CONFLICT');}
            if($prior!==null && Connection::number($prior,'attempts')>=3){throw new ContractViolation(ErrorCode::ManualResolutionRequired);}
            $j->db->execute('INSERT INTO '.$j->db->table('demo_delivery_attempts').'(tenant_id,id,attempts,payload_hash,body_hash,actor_ref,policy_revision,visibility_ref)
                VALUES (:tenant,:id,0,:hash,:body,:actor,:policy,:visibility) ON CONFLICT DO NOTHING',
                ['tenant'=>$j->tenant->value,'id'=>$id,'hash'=>$hash,'body'=>$bodyHash,'actor'=>$j->context->identity->actorRef->value,
                    'policy'=>$j->context->identity->policyRevision,'visibility'=>'host-visibility:ST-17']);
            $j->db->execute('UPDATE '.$j->db->table('delivery_intents').' SET version=version+1,outcome=\'unknown\',receipt_ref=NULL WHERE tenant_id=:tenant AND id=:id',
                ['tenant'=>$j->tenant->value,'id'=>$id]);
        });
        $j->boundary('delivery');$this->authorize();
        try{$message=$this->destinationSubmit($id,$hash,$bodyHash,$body);}catch(\RuntimeException){$this->record($id,$hash,'unknown',null);return 'unknown';}
        $this->record($id,$hash,'success',$message);return 'delivered';
    }
    private function destinationStatus(string $id,string $hash,string $bodyHash):?string
    {
        $this->authorize();$j=$this->journey;
        $row=$j->db->row('SELECT message_ref,payload_hash,body_hash FROM '.$j->db->table('demo_messages').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$j->tenant->value,'id'=>$id]);
        if($row===null){return null;}if(Connection::text($row,'payload_hash')!==$hash || Connection::text($row,'body_hash')!==$bodyHash){throw new IntegrityConflict('DELIVERY_PAYLOAD_CONFLICT');}
        return Connection::text($row,'message_ref');
    }
    private function destinationSubmit(string $id,string $hash,string $bodyHash,string $body):string
    {
        $j=$this->journey;$this->authorize();
        $message='destination-message:'.substr($j->hasher->hash($j->tenant,'destination-message',$id)->value,8);
        // The scripted destination owns this SQL operation. Count actual acceptance atomically
        // with its native-key message, never the earlier local preparation/visibility window.
        $j->db->transaction(function()use($j,$id,$message,$hash,$bodyHash,$body):void{
            $attempt=$j->db->row('SELECT attempts,payload_hash,body_hash FROM '.$j->db->table('demo_delivery_attempts').' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',
                ['tenant'=>$j->tenant->value,'id'=>$id]);
            if($attempt===null || Connection::text($attempt,'payload_hash')!==$hash || Connection::text($attempt,'body_hash')!==$bodyHash){throw new IntegrityConflict('DELIVERY_PAYLOAD_CONFLICT');}
            if(Connection::number($attempt,'attempts')>=3){throw new ContractViolation(ErrorCode::ManualResolutionRequired);}
            $insert=$j->db->execute('INSERT INTO '.$j->db->table('demo_messages').'(tenant_id,id,message_ref,payload_hash,body_hash,body) VALUES (:tenant,:id,:message,:hash,:body_hash,:body) ON CONFLICT DO NOTHING',
                ['tenant'=>$j->tenant->value,'id'=>$id,'message'=>$message,'hash'=>$hash,'body_hash'=>$bodyHash,'body'=>$body]);
            if($insert->rowCount()===1){$j->db->execute('UPDATE '.$j->db->table('demo_delivery_attempts').' SET attempts=attempts+1 WHERE tenant_id=:tenant AND id=:id',['tenant'=>$j->tenant->value,'id'=>$id]);}
        });
        $verified=$this->destinationStatus($id,$hash,$bodyHash)??throw new \RuntimeException('Destination unavailable');
        $j->boundary('destination');if($j->loseDestinationAck){throw new \RuntimeException('Scripted ack loss');}return $verified;
    }
    private function record(string $id,string $hash,string $outcome,?string $receipt):void
    {
        $j=$this->journey;$this->authorize();
        $j->db->transaction(function()use($j,$id,$hash,$outcome,$receipt):void{
            $row=$j->db->row('SELECT outcome,receipt_ref FROM '.$j->db->table('delivery_intents').' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',['tenant'=>$j->tenant->value,'id'=>$id]);
            if($row===null){throw new ContractViolation(ErrorCode::AccessDenied);}
            if($row['outcome']==='success'){
                if($outcome!=='success' || $row['receipt_ref']!==$receipt){throw new IntegrityConflict('Known delivery changed');}return;
            }
            $last=$j->db->row('SELECT coalesce(max(receipt_version),0)+1 AS next FROM '.$j->db->table('delivery_receipts').' WHERE tenant_id=:tenant AND delivery_id=:id', ['tenant'=>$j->tenant->value,'id'=>$id]);
            $version=$last===null?1:Connection::number($last,'next');
            $j->db->execute('INSERT INTO '.$j->db->table('delivery_receipts').'(tenant_id,id,delivery_id,receipt_version,payload_hash,outcome,receipt_ref) VALUES (:tenant,:receipt_id,:id,:version,:hash,:outcome,:receipt)',
                ['tenant'=>$j->tenant->value,'receipt_id'=>$id.':receipt:'.$version,'id'=>$id,'version'=>$version,'hash'=>$hash,'outcome'=>$outcome,'receipt'=>$receipt]);
            $j->db->execute('UPDATE '.$j->db->table('delivery_intents').' SET version=version+1,outcome=:outcome,receipt_ref=:receipt WHERE tenant_id=:tenant AND id=:id',
                ['tenant'=>$j->tenant->value,'id'=>$id,'outcome'=>$outcome,'receipt'=>$receipt]);
        });
    }
}
