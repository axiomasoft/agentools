<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Tests\Journeys;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, InputPayload, IntegrityConflict, PayloadHash};
use AgenToolsPlatform\Core\Security\TrustedInput;
use AgenToolsPlatform\Examples\ReadJourney\SupportJourney;
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class HeadlessReadJourneyTest extends TestCase
{
    private IsolatedDatabase $database;
    /** @var list<string> */
    private array $schemas=[];
    protected function setUp():void{$this->database=new IsolatedDatabase();}
    protected function tearDown():void
    {
        if($this->database->pdo->inTransaction()){$this->database->pdo->rollBack();}
        foreach($this->schemas as $schema){$this->database->dropSchema($schema);}
    }
    private function journey():SupportJourney
    {
        $schema='p3_'.bin2hex(random_bytes(12));SupportJourney::install($this->database,$schema);$this->schemas[]=$schema;
        return new SupportJourney($this->database,$schema);
    }
    private function fresh(SupportJourney $j):SupportJourney{return new SupportJourney(new IsolatedDatabase(),$j->schema,$j->tenant->value,$j->sourceNamespace);}
    /** @param callable():mixed $operation */
    private function rejects(callable $operation,ErrorCode $code):void
    {
        try{$operation();self::fail('Expected '.$code->value);}catch(ContractViolation $error){self::assertSame($code,$error->errorCode);}
    }
    private function assertCompleted(SupportJourney $j):void
    {
        foreach(['turns','runs','proposal_map','action_current','responses','delivery_intents','demo_reads'] as $table){self::assertSame(1,$j->count($table),$table);}
        self::assertSame(2,$j->count('invocation_intents'));self::assertSame(2,$j->count('usage_versions'));self::assertSame(2,$j->count('demo_transport_calls'));
        self::assertSame(3,$j->count('agentools_events'));self::assertSame(0,$j->count('effect_intents'));
        $state=$j->db->row('SELECT status,claim_owner FROM '.$j->db->table('runs'));self::assertNotNull($state);self::assertSame('completed',$state['status']);self::assertNull($state['claim_owner']);
    }
    public function testSyntheticReadRequest():void
    {
        $j=$this->journey();$accepted=$j->accept();$j->drive();$this->assertCompleted($j);
        self::assertSame(0,$j->count('demo_messages')); // Completed is not delivered.
        self::assertSame('delivered',$j->delivery->deliver());self::assertSame(1,$j->count('demo_messages'));
        $message=$j->db->row('SELECT body FROM '.$j->db->table('demo_messages'));self::assertNotNull($message);self::assertSame('Your ticket ST-17 is open.',$message['body']);
        self::assertSame($accepted->runId->value,$j->runId->value);
        $wake=$j->db->row('SELECT count(*) AS total FROM '.$j->db->table('outbox').' WHERE event_type=\'run.accepted\' AND status=\'dispatched\'');
        self::assertNotNull($wake);self::assertSame(1,Connection::number($wake,'total'));self::assertSame(1,$j->count('demo_signals'));
    }
    public function testToolAllowlistBeforeModel():void
    {
        $j=$this->journey();$j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET tool_allowed=false');
        $j->accept();$plan=$j->plan();self::assertSame([],$plan->request->tools);self::assertSame([],$plan->tools);
        $j->drive();self::assertSame(0,$j->count('proposal_map'));self::assertSame(0,$j->count('demo_reads'));self::assertSame(1,$j->count('demo_transport_calls'));
        self::assertSame(0,$j->count('agentools_events'));
    }
    public function testCurrentOwnerAclBeforeResult():void
    {
        $j=$this->journey();$j->accept();self::assertSame('waiting_action',$j->driveOnce());
        $j->barrier=static function(string $boundary)use($j):void{if($boundary==='owner'){$j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET disclosure_allowed=false');}};
        $this->rejects(fn()=>$j->driveOnce(),ErrorCode::AccessDenied);
        self::assertSame(0,$j->count('demo_reads'));self::assertSame(0,$j->count('responses'));self::assertSame(1,$j->count('demo_transport_calls'));
        $row=$j->db->row('SELECT state FROM '.$j->db->table('action_current'));self::assertNotNull($row);self::assertStringNotContainsString('PRIVATE-OWNER-NOTE',Connection::text($row,'state'));
    }
    /** @return array<string,list<string>> */
    private function identities(SupportJourney $j):array
    {
        $result=[];foreach(['turns','runs','invocation_intents','proposal_map','action_current','responses','delivery_intents','usage_versions','demo_provider','demo_vault'] as $table){
            $rows=$j->db->execute('SELECT id FROM '.$j->db->table($table).' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
            $ids=[];foreach($rows as $value){self::assertIsString($value);$ids[]=$value;}$result[$table]=$ids;
        }return $result;
    }
    private function killAt(SupportJourney $j,string $boundary):void
    {
        $path=dirname(__DIR__,4).'/conformance/platform/read-journey/worker.php';
        $process=proc_open([PHP_BINARY,$path,$j->schema,$boundary],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $out='';$err='';$deadline=hrtime(true)+10_000_000_000;
        try{while(hrtime(true)<$deadline && !str_contains($out,'boundary:'.$boundary)){
            $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);if(!proc_get_status($process)['running']){break;}usleep(1000);
        }self::assertStringContainsString('boundary:'.$boundary,$out,$err);}
        finally{proc_terminate($process,9);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}
        $j->expireWorker();
    }
    public function testCrashAtEveryBoundary():void
    {
        foreach(['intake','signal','provider','payload','proposal','action','read-result','read-complete','response','delivery','destination'] as $boundary){
            $j=$this->journey();$this->killAt($j,$boundary);$before=$this->identities($j);$fresh=$this->fresh($j);
            $fresh->accept();$fresh->drive();self::assertSame('delivered',$fresh->delivery->deliver(),$boundary);$this->assertCompleted($fresh);
            $after=$this->identities($fresh);foreach($before as $table=>$ids){foreach($ids as $id){self::assertContains($id,$after[$table],$boundary.':'.$table);}}
            self::assertSame(1,$fresh->count('demo_messages'),$boundary);
        }
    }
    public function testDeliveryAckLoss():void
    {
        $j=$this->journey();$j->accept();$j->drive();$before=$this->identities($j);$j->loseDestinationAck=true;
        self::assertSame('unknown',$j->delivery->deliver());self::assertSame(1,$j->count('demo_messages'));
        $fresh=$this->fresh($j);self::assertSame('delivered',$fresh->delivery->deliver());self::assertSame($before,$this->identities($fresh));
        self::assertSame(1,$fresh->count('demo_messages'));self::assertSame(2,$fresh->count('demo_transport_calls'));
        self::assertSame(2,$fresh->count('delivery_receipts'));
    }
    public function testDeliveryCurrentVisibility():void
    {
        $j=$this->journey();$j->accept();$j->drive();$j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET delivery_allowed=false');
        $this->rejects(fn()=>$j->delivery->deliver(),ErrorCode::AccessDenied);self::assertSame(0,$j->count('demo_messages'));
        $j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET delivery_allowed=true');
        $j->db->execute('UPDATE '.$j->db->table('demo_tickets').' SET visible=false');
        $this->rejects(fn()=>$j->delivery->deliver(),ErrorCode::AccessDenied);self::assertSame(0,$j->count('demo_messages'));
    }
    public function testStableIdentityAndHashConflict():void
    {
        $j=$this->journey();$one=$j->accept();$two=$j->accept();self::assertSame($one->toArray(),$two->toArray());
        $changed=new TrustedInput($j->context,$j->source,new InputPayload($j->ref('input:request-17'),new PayloadHash('sha-256:'.str_repeat('b',64)),$j->ref('conversation:synthetic'),$j->ref('message:request-17')));
        try{(new IntakeRepository($j->environment))->accept($changed,$j->compiled()->snapshot);self::fail('Expected source conflict');}catch(IntegrityConflict){self::assertSame(1,$j->count('runs'));}
        $different='{"ticket_ref":"ST-999"}';
        try{$j->vault->put($j->context,$j->ref('input:request-17'),new PayloadHash('sha-256:'.hash('sha256',$different)),$different);self::fail('Expected immutable payload conflict');}
        catch(IntegrityConflict){self::assertSame(1,$j->count('turns'));}
        $j->drive();$j->delivery->deliver();$j->db->execute('UPDATE '.$j->db->table('demo_messages').' SET body_hash=:bad',['bad'=>str_repeat('b',64)]);
        try{$j->delivery->deliver();self::fail('Expected delivery conflict');}catch(IntegrityConflict){self::assertSame(1,$j->count('demo_messages'));}
        self::assertSame(2,$j->count('demo_transport_calls'));
    }
    public function testBudgetOutageAndCurrentEmergency():void
    {
        foreach(['unavailable','denied','unknown'] as $state){$j=$this->journey();$j->accept();$j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET budget_state=:state',['state'=>$state]);
            $this->rejects(fn()=>$j->driveOnce(),ErrorCode::AdmissionDenied);self::assertSame(0,$j->count('demo_transport_calls'));self::assertSame(0,$j->count('invocation_intents'));}
        $j=$this->journey();$j->accept();$j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET emergency=true');
        $this->rejects(fn()=>$j->driveOnce(),ErrorCode::DeploymentUnavailable);self::assertSame(0,$j->count('demo_transport_calls'));
        $j=$this->journey();$j->accept();$j->driveOnce();$j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET emergency=true');
        $this->rejects(fn()=>$j->driveOnce(),ErrorCode::DeploymentUnavailable);self::assertSame(0,$j->count('demo_reads'));self::assertSame(1,$j->count('demo_transport_calls'));
        $j=$this->journey();$j->accept();$j->driveOnce();$j->guard->cancelled=true;
        $this->rejects(fn()=>$j->driveOnce(),ErrorCode::Cancelled);self::assertSame(0,$j->count('demo_reads'));

    }
    public function testSecondGenericHost():void
    {
        $one=$this->journey();$one->accept();$one->drive();$one->delivery->deliver();
        $batch=new SupportJourney(new IsolatedDatabase(),$one->schema,'batch-tenant','batch');$batch->accept();$batch->drive();$batch->delivery->deliver();
        self::assertNotSame($one->runId->value,$batch->runId->value);self::assertSame(2,$batch->count('demo_reads'));self::assertSame(2,$batch->count('demo_messages'));
        $row=$batch->db->row('SELECT destination FROM '.$batch->db->table('delivery_intents').' WHERE tenant_id=\'batch-tenant\'');self::assertNotNull($row);self::assertSame('batch:result-17',$row['destination']);
        $this->rejects(fn()=>$batch->vault->get($batch->context,$one->ref('input:request-17')),ErrorCode::AccessDenied);
    }
    public function testRunnableExample():void
    {
        $process=proc_open([PHP_BINARY,dirname(__DIR__,4).'/examples/platform/read-journey/run.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$err);$data=json_decode($out,true,32,JSON_THROW_ON_ERROR);self::assertIsArray($data);
        self::assertSame('local-host-e2e',$data['tier']);self::assertSame('scripted-support/v1',$data['transport']);self::assertSame('delivered',$data['delivery']);self::assertSame(2,$data['provider_exchanges']);
    }
    public function testNoRawOwnerDataAndEncryptedVault():void
    {
        $j=$this->journey();$j->accept();$j->drive();$j->delivery->deliver();
        foreach(['demo_snapshots'=>'manifest','demo_vault'=>'body','demo_messages'=>'body','action_current'=>'state','runs'=>'checkpoint'] as $table=>$column){
            $rows=$j->db->execute('SELECT '.$column.' FROM '.$j->db->table($table))->fetchAll(\PDO::FETCH_COLUMN);
            foreach($rows as $text){self::assertIsString($text);self::assertStringNotContainsString('PRIVATE-OWNER-NOTE',$text);}
        }
        $sealed=$j->db->row('SELECT body FROM '.$j->db->table('demo_vault').' WHERE id=\'input:request-17\'');self::assertNotNull($sealed);self::assertStringNotContainsString('ticket_ref',Connection::text($sealed,'body'));
        $otherSession=new SupportJourney(new IsolatedDatabase(),$j->schema,$j->tenant->value,'other-session');
        $this->rejects(fn()=>$otherSession->vault->get($otherSession->context,$j->ref('input:request-17')),ErrorCode::AccessDenied);
        $j->db->execute('UPDATE '.$j->db->table('demo_vault').' SET expires_at=clock_timestamp()-interval \'1 microsecond\'');
        $this->rejects(fn()=>$j->vault->get($j->context,$j->ref('input:request-17')),ErrorCode::AccessDenied);
    }
    public function testSnapshotPinsAndTokenManifest():void
    {
        $j=$this->journey();$one=$j->compiled();$two=$j->compiled();self::assertSame($one->snapshot->toArray(),$two->snapshot->toArray());
        $manifest=json_decode($one->manifestJson,true,64,JSON_THROW_ON_ERROR);self::assertIsArray($manifest);self::assertSame('conservative',$manifest['count_confidence']);
        self::assertSame('scripted-support-byte-bound/v1',$manifest['tokenizer_version']);self::assertSame(256,$manifest['output_reserve']);
        self::assertLessThanOrEqual($manifest['available_tokens'],$manifest['used_tokens']);self::assertSame($j->bundle->toArray(),$manifest['bundle']);
        self::assertSame($j->runId->value,$manifest['run_id']);self::assertStringNotContainsString('PRIVATE-OWNER-NOTE',$one->rendered);
    }
    public function testF1PreSendDenialIsRecoverable():void
    {
        foreach(['disclosure_allowed'=>ErrorCode::AccessDenied,'emergency'=>ErrorCode::DeploymentUnavailable] as $field=>$code){
            $j=$this->journey();$j->accept();self::assertSame('waiting_action',$j->driveOnce());self::assertSame('continued',$j->driveOnce());
            $finalId=$j->plan(true)->request->invocationId->value;
            $j->barrier=static function(string $boundary)use($j,$field):void{
                if($boundary==='intent'){$j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET '.$field.'='.($field==='emergency'?'true':'false'));}
            };
            $this->rejects(fn()=>$j->driveOnce(),$code);
            self::assertSame(1,$j->count('demo_transport_calls'));self::assertSame(1,$j->count('demo_provider'));
            self::assertSame(1,$j->count('invocation_results'));self::assertSame(1,$j->count('usage_versions'));
            $intent=$j->db->row('SELECT id FROM '.$j->db->table('invocation_intents').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$j->tenant->value,'id'=>$finalId]);
            self::assertNotNull($intent);self::assertSame($finalId,$intent['id']);
            $j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET '.$field.'='.($field==='emergency'?'false':'true'));$j->barrier=null;$j->expireWorker();
            $marker=$j->db->row('SELECT request_hash FROM '.$j->db->table('demo_not_sent').' WHERE tenant_id=:tenant AND invocation_id=:id',['tenant'=>$j->tenant->value,'id'=>$finalId]);
            self::assertNotNull($marker);$hash=Connection::text($marker,'request_hash');
            $j->db->execute('UPDATE '.$j->db->table('demo_not_sent').' SET request_hash=:hash',['hash'=>'sha-256:'.str_repeat('b',64)]);
            try{$j->driveOnce();self::fail('Wrong-request not-sent evidence accepted');}catch(IntegrityConflict){self::assertSame(1,$j->count('demo_transport_calls'));}
            $j->db->execute('UPDATE '.$j->db->table('demo_not_sent').' SET request_hash=:hash',['hash'=>$hash]);$j->expireWorker();
            $fresh=$this->fresh($j);$fresh->drive();self::assertSame('delivered',$fresh->delivery->deliver());$this->assertCompleted($fresh);
            self::assertSame(0,$fresh->count('demo_not_sent'));self::assertSame(1,$fresh->count('demo_messages'));
            $result=$fresh->db->row('SELECT invocation_id FROM '.$fresh->db->table('invocation_results').' WHERE invocation_id=:id',['id'=>$finalId]);
            self::assertNotNull($result);self::assertSame($finalId,$result['invocation_id']);
        }
    }
    public function testF2PreSendDeliveryDoesNotExhaust():void
    {
        foreach(['denied','killed'] as $fault){
            $j=$this->journey();$j->accept();$j->drive();$before=$this->identities($j);
            for($i=0;$i<3;++$i){
                if($fault==='denied'){
                    $j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET delivery_allowed=true');
                    $j->barrier=static function(string $boundary)use($j):void{
                        if($boundary==='delivery'){$j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET delivery_allowed=false');}
                    };
                    $this->rejects(fn()=>$j->delivery->deliver(),ErrorCode::AccessDenied);
                }else{$this->killAt($j,'delivery');}
                self::assertSame(0,$j->count('demo_messages'));
                $attempt=$j->db->row('SELECT attempts FROM '.$j->db->table('demo_delivery_attempts'));self::assertNotNull($attempt);self::assertSame(0,Connection::number($attempt,'attempts'));
            }
            $j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET delivery_allowed=true');$j->barrier=null;
            $fresh=$this->fresh($j);self::assertSame('delivered',$fresh->delivery->deliver());self::assertSame(1,$fresh->count('demo_messages'));
            self::assertSame($before,$this->identities($fresh));self::assertSame(2,$fresh->count('demo_transport_calls'));
            $attempt=$fresh->db->row('SELECT attempts FROM '.$fresh->db->table('demo_delivery_attempts'));self::assertNotNull($attempt);self::assertSame(1,Connection::number($attempt,'attempts'));
        }
        // The actual-send bound still refuses exhausted unresolved attempts.
        $j=$this->journey();$j->accept();$j->drive();
        $j->barrier=static function(string $boundary)use($j):void{if($boundary==='delivery'){$j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET delivery_allowed=false');}};
        $this->rejects(fn()=>$j->delivery->deliver(),ErrorCode::AccessDenied);
        $j->db->execute('UPDATE '.$j->db->table('demo_delivery_attempts').' SET attempts=3');
        $j->db->execute('UPDATE '.$j->db->table('demo_permissions').' SET delivery_allowed=true');$j->barrier=null;
        $this->rejects(fn()=>$j->delivery->deliver(),ErrorCode::ManualResolutionRequired);self::assertSame(0,$j->count('demo_messages'));
    }

}
