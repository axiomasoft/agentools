<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Conformance\WriteJourney;

use AgenTools\Action\{ActionReducer, JournalHashChain};
use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Actions\Cancellation\{Authority, Operation};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Outcome, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Laravel\Operations\Operations;
use AgenToolsPlatform\Postgres\Actions\{ActionRepository, Projection};
use AgenToolsPlatform\Postgres\Confirmation\Journal;
use AgenToolsPlatform\Postgres\Effects\Service as Effects;
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Operations\Audit;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RunRepository};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class RecoveryTest extends TestCase
{
    private IsolatedDatabase $database;
    /** @var list<string> */ private array $schemas=[];
    protected function setUp(): void { $this->database=new IsolatedDatabase(); }
    protected function tearDown(): void
    {
        if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); }
        foreach ($this->schemas as $schema) { $this->database->dropSchema($schema); }
        gc_collect_cycles();
    }
    private function journey(?string $migrations=null): Journey
    {
        $schema='p3_'.bin2hex(random_bytes(12)); $this->schemas[]=$schema;
        Journey::install($this->database,$schema,$migrations); return new Journey($this->database,$schema);
    }
    /** @param callable():mixed $call @param class-string<\Throwable> $type */
    private function denies(callable $call,string $type=\Throwable::class): void
    {
        try { $call(); } catch (\Throwable $e) { self::assertTrue(is_a($e,$type),'Expected '.$type.', got '.$e::class.': '.$e->getMessage()); return; }
        self::fail('Expected recovery guard denial');
    }

    public function testCrashAtEveryBoundary(): void
    {
        $raw=file_get_contents(dirname(__DIR__,3).'/fixtures/platform/v1/recovery/write-matrix.json');
        if ($raw===false) { self::fail('Missing required crash matrix'); }
        /** @var array{boundaries:list<array{id:string,expected:string}>} $matrix */
        $matrix=json_decode($raw,true,512,JSON_THROW_ON_ERROR); self::assertCount(19,$matrix['boundaries']);
        $facts=[];
        foreach ($matrix['boundaries'] as $case) {
            $j=$this->journey(); $worker=new Worker($j->schema,$case['id']); $worker->go();
            self::assertSame('boundary:'.$case['id'],$worker->line(),$case['id']);
            self::assertTrue($worker->alive());
            $before=['owner_writes'=>$j->count('example_owner_writes'),'provider_calls'=>$j->count('write_provider')];
            if ($case['id']==='owner-commit') {
                $view=$j->actions->status($j->proposal()->actionId,$j->context);
                self::assertSame('applying',$view->receipt->wireStatus); self::assertSame(Outcome::Unknown,$view->outcome());
                self::assertSame(1,$before['owner_writes']); $before['outcome']='unknown';
            }
            $worker->kill(); $exit=$worker->finish()['exit']; self::assertNotSame(0,$exit);
            $fresh=new Journey(new IsolatedDatabase(),$j->schema); $fresh->expire();
            $status=$fresh->drive(); self::assertSame($case['expected'],$status,$case['id']);
            if ($status==='completed') {
                $fresh->deliver(); self::assertSame(1,$fresh->count('example_owner_writes'));
                self::assertSame(1,$fresh->count('write_messages')); self::assertSame(2,$fresh->count('write_provider'));
                self::assertSame(1,$fresh->count('action_submissions')); self::assertSame(1,$fresh->count('proposal_map'));
                self::assertSame(1,$fresh->count('confirmation_consumed_tokens')); self::assertSame(2,$fresh->count('usage_versions'));
            } else {
                self::assertSame(0,$fresh->count('write_provider')); self::assertSame(0,$fresh->count('example_owner_writes'));
                self::assertSame(0,$fresh->count('proposal_map'));
                $row=$fresh->db->row('SELECT input_tokens,output_tokens FROM '.$fresh->db->table('usage_versions'));
                self::assertNotNull($row); self::assertNull($row['input_tokens']); self::assertNull($row['output_tokens']);
                self::assertSame('unresolved',$fresh->drive()); self::assertSame(1,$fresh->count('invocation_intents'));
            }
            self::assertSame(1,$fresh->count('turns')); self::assertSame(1,$fresh->count('runs')); self::assertSame(1,$fresh->count('write_signals'));
            $facts[$case['id']]=['worker_exit'=>$exit,'before'=>$before,'after'=>['status'=>$status,'owner_writes'=>$fresh->count('example_owner_writes'),
                'provider_calls'=>$fresh->count('write_provider'),'actions'=>$fresh->count('action_submissions'),'usage_versions'=>$fresh->count('usage_versions')]];
            unset($j,$fresh,$worker);
            gc_collect_cycles();
        }
        Report::record('crash-matrix',['tier'=>'local-host-e2e','boundaries'=>$facts]);
    }

    public function testLiveZombieAfterLeaseExpiry(): void
    {
        $j=$this->journey(); $j->accept(); $j->advance(); $grant=$j->confirm();
        $worker=new Worker($j->schema,'zombie'); $worker->go(); self::assertSame('prepared',$worker->line());
        self::assertTrue($worker->alive()); $j->db->execute('SELECT pg_sleep(1.05)');
        $claim=$j->claim(); self::assertTrue($worker->alive());
        $old=$j->ledger($grant); $request=$old->request(); $new=$old->takeover();
        (new Effects($new,new Owner($j,$new->epoch)))->reconcile(true);
        $schema='"'.$j->schema.'"';
        $j->database->pdo->exec('CREATE FUNCTION '.$schema.'.zombie_write_denied() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'zombie replay forbidden\'; END $$');
        $j->database->pdo->exec('CREATE TRIGGER zombie_write_denied BEFORE INSERT OR UPDATE OR DELETE ON '.$schema.'.example_owner_writes FOR EACH ROW EXECUTE FUNCTION '.$schema.'.zombie_write_denied()');
        $worker->send("resume\n"); $result=$worker->finish(); self::assertSame(0,$result['exit'],$result['stderr']);
        $facts=json_decode(trim($result['stdout']),true,512,JSON_THROW_ON_ERROR); self::assertIsArray($facts);
        self::assertTrue($facts['stale_run']); self::assertTrue($facts['stale_effect']); self::assertSame(1,$facts['owner_writes']);
        self::assertSame($request->operationKey,$new->request()->operationKey);
        self::assertSame($request->requestedEpoch,$new->request()->requestedEpoch);
        self::assertSame($new->epoch,(int)$j->db->execute('SELECT epoch FROM '.$j->db->table('agentools_events')." WHERE kind='effect_confirmed'")->fetchColumn());
        $j->db->execute('UPDATE '.$j->db->table('runs').' SET version=version+1,claim_owner=NULL,lease_until=NULL WHERE tenant_id=:tenant AND id=:id AND claim_owner=:owner AND fencing_epoch=:epoch',
            ['tenant'=>$j->tenant->value,'id'=>$j->run->value,'owner'=>$claim->owner,'epoch'=>$claim->epoch]);
        self::assertSame('completed',$j->drive()); $j->deliver(); self::assertSame(1,$j->count('example_owner_writes'));
        Report::record('zombie',['tier'=>'local-host-e2e','old_worker_alive_after_db_lease_expiry'=>true,'facts'=>$facts,
            'operation_key'=>$request->operationKey,'request_hash'=>$request->requestHash->value,'original_epoch'=>$request->requestedEpoch,'accepted_epoch'=>$new->epoch]);
    }

    public function testConcurrentSourceDelivery(): void
    {
        $j=$this->journey(); $pdo=$j->database->pdo; $pdo->beginTransaction();
        $pdo->exec('LOCK TABLE '.$j->db->table('turns').' IN ACCESS EXCLUSIVE MODE');
        $workers=[];
        try {
            foreach (['source-a','source-b'] as $name) { $workers[]=new Worker($j->schema,$name); }
            foreach ($workers as $worker) { $worker->go(); }
            $deadline=hrtime(true)+10_000_000_000; $waiting=0;
            do {
                $stmt=$pdo->prepare("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND application_name LIKE :prefix AND wait_event_type='Lock'");
                $stmt->execute(['prefix'=>'write_'.$j->schema.'_%']); $waiting=(int)$stmt->fetchColumn(); if ($waiting===2) { break; } usleep(1000);
            } while (hrtime(true)<$deadline);
            self::assertSame(2,$waiting,'Concurrent duplicate sources must overlap at the SQL barrier');
        } finally { $pdo->rollBack(); }
        foreach ($workers as $worker) { $result=$worker->finish(); self::assertSame(0,$result['exit'],$result['stderr']); }
        self::assertSame(1,$j->count('turns')); self::assertSame(1,$j->count('runs'));
        self::assertSame(0,$j->count('write_provider')); self::assertSame(1,$j->count('write_signals'));
        self::assertSame('completed',$j->drive()); $j->deliver(); self::assertSame(1,$j->count('example_owner_writes'));
    }

    public function testDrainUnknownEffect(): void
    {
        $j=$this->journey(); $j->accept(); $j->advance(); $grant=$j->confirm(); $ledger=$j->ledger($grant);
        $request=$ledger->prepare('synthetic.documents',\AgenToolsPlatform\Core\Actions\Effects\Strategy::NativeKey);
        (new Owner($j,$ledger->epoch))->execute($request); $ledger->unknown('owner_reply_lost');
        $authority=new class implements Authority { public function authorize(Operation $operation): void {} };
        $operations=new Operations(new Audit($j->environment,$authority),$j->actions,$j->composition->registry,$j->policy,$j->confirmation);
        $run=(new RunRepository($j->environment))->get($j->context,$j->run)??throw new \LogicException('No run');
        $hash=$j->hasher->hash($j->tenant,'drain-operation',[]);
        $cancel=new Operation($j->ref('drain'),'run_cancel',$j->run,$run->version,$run->fencingEpoch,'drain',null,$hash,$j->context,$j->hasher);
        $saved=$operations->cancelRun($cancel);
        self::assertIsInt($saved['version']); self::assertIsInt($saved['epoch']);
        $finish=new Operation($j->ref('drain-finish'),'run_finalize',$j->run,$saved['version'],$saved['epoch'],'drained',$j->ref('policy:verified-drain'),$hash,$j->context,$j->hasher);
        $this->denies(fn ()=>$operations->cancelRun($finish),ContractViolation::class);
        (new Effects($ledger,new Owner($j,$ledger->epoch)))->reconcile();
        self::assertSame('cancelled',$operations->cancelRun($finish)['status']);
        self::assertSame(Outcome::Succeeded,$j->actions->status($grant->action,$j->context)->outcome());
        self::assertSame(1,$j->count('write_provider')); self::assertSame(1,$j->count('example_owner_writes')); self::assertSame(0,$j->count('write_messages'));
        $this->denies(fn ()=>$j->advance(),ContractViolation::class);
    }

    public function testAtomicDurableBoundaries(): void
    {
        $cases=['model-result'=>'usage_versions','action-submit'=>'agentools_events','preview'=>'confirmation_previews',
            'effect-intent'=>'effect_intents','delivery-intent'=>'delivery_intents','delivery-receipt'=>'delivery_receipts']; $facts=[];
        foreach ($cases as $name=>$table) {
            $j=$this->journey(); $j->accept();
            if (in_array($name,['effect-intent','delivery-intent','delivery-receipt'],true)) { $j->advance(); }
            if ($name==='effect-intent') { $j->confirm(); }
            if (in_array($name,['delivery-intent','delivery-receipt'],true)) { $j->write(); $j->advance(); }
            if ($name==='delivery-receipt') { $j->advance(true); }
            $schema='"'.$j->schema.'"'; $condition=$name==='action-submit'?"IF NEW.kind='action_created' THEN RAISE EXCEPTION 'atomic fault'; END IF;":"RAISE EXCEPTION 'atomic fault';";
            $j->database->pdo->exec('CREATE FUNCTION '.$schema.'.atomic_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN '.$condition.' RETURN NULL; END $$');
            $j->database->pdo->exec('CREATE CONSTRAINT TRIGGER atomic_fault AFTER INSERT ON '.$schema.'.'.$table.' DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION '.$schema.'.atomic_fault()');
            try {
                if ($name==='effect-intent') { $this->denies(fn ()=>$j->write()); }
                elseif ($name==='delivery-receipt') { $this->denies(fn ()=>$j->deliver()); }
                else { $this->denies(fn ()=>$j->advance($name==='delivery-intent')); }
            } finally { $j->database->pdo->exec('DROP TRIGGER atomic_fault ON '.$schema.'.'.$table); }
            if ($name==='model-result') { self::assertSame(0,$j->count('usage_versions')); self::assertSame(0,$j->count('proposal_map')); }
            if ($name==='action-submit') { self::assertSame(0,$j->count('action_submissions')); self::assertSame(0,$j->count('agentools_events')); }
            if ($name==='preview') { self::assertSame(0,$j->count('confirmation_previews')); self::assertSame(0,$j->count('confirmation_consumed_tokens')); }
            if ($name==='effect-intent') { self::assertSame(0,$j->count('effect_intents')); self::assertSame(0,$j->count('example_owner_writes')); }
            if ($name==='delivery-intent') { self::assertSame(0,$j->count('responses')); self::assertSame(0,$j->count('delivery_intents')); self::assertSame(1,$j->count('example_owner_writes')); }
            if ($name==='delivery-receipt') { self::assertSame(0,$j->count('delivery_receipts')); self::assertSame(1,$j->count('write_messages')); }
            $j->expire(); self::assertSame('completed',$j->drive()); $j->deliver();
            self::assertSame(1,$j->count('example_owner_writes')); self::assertSame(1,$j->count('write_messages')); self::assertSame(2,$j->count('write_provider'));
            $facts[$name]=['rolled_back'=>true,'owner_writes'=>$j->count('example_owner_writes'),'messages'=>$j->count('write_messages'),'provider_calls'=>$j->count('write_provider')];
            unset($j);
            gc_collect_cycles();
        }
        Report::record('atomic-matrix',['tier'=>'local-host-e2e','boundaries'=>$facts]);
    }

    public function testCompatibleRollbackReader(): void
    {
        $directory=sys_get_temp_dir().'/write-migrations-'.bin2hex(random_bytes(8)); mkdir($directory);
        try {
            foreach (glob(dirname(__DIR__,3).'/packages/agentools-postgres/migrations/*.sql')?:[] as $file) {
                if (basename($file)<'0011_') { copy($file,$directory.'/'.basename($file)); }
            }
            $j=$this->journey($directory); self::assertSame('completed',$j->drive()); $j->deliver();
            $id=$j->proposal()->actionId; $before=(new Projection((new Journal($j->environment))->read($id)))->digest();
            $events=$j->db->journal()->load($j->tenant->value,$id->value)->events;
            $oldReader=new ActionReducer('2026-09',new JournalHashChain(new Canonicalizer()));
            self::assertSame('completed',$oldReader->fold($events)->status?->value);
            self::assertSame(['0011_operator_operations.sql','0012_derived_payloads.sql'],(new Migrator($j->database->pdo,$j->schema))->migrate());
            self::assertSame([],(new Migrator($j->database->pdo,$j->schema))->migrate());
            self::assertSame('completed',$oldReader->fold($j->db->journal()->load($j->tenant->value,$id->value)->events)->status?->value);
            $j->db->execute('DELETE FROM '.$j->db->table('action_current').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$j->tenant->value,'id'=>$id->value]);
            $rebuilt=(new ActionRepository($j->environment))->rebuild($j->context,$id,$before);
            self::assertSame($before,$rebuilt->digest()); self::assertSame(count($events),$rebuilt->state->seq);
            self::assertSame(1,$j->count('example_owner_writes')); self::assertSame(1,$j->count('write_messages'));
            Report::record('rollback',['tier'=>'local-host-e2e','upgrade'=>['0011_operator_operations.sql','0012_derived_payloads.sql'],'reader_revision'=>'2026-09',
                'projection_digest'=>$before,'event_count'=>count($events),'owner_writes'=>1,'route'=>'old dated reader on additive schema + verified projection rebuild; no destructive down migration']);
        } finally { foreach (glob($directory.'/*.sql')?:[] as $file) { unlink($file); } rmdir($directory); }
    }

    public function testPrivacyAndResultBinding(): void
    {
        $j=$this->journey(); $j->drive(); $j->deliver();
        foreach (['agentools_events'=>'payload_json','run_steps'=>'state_ref'] as $table=>$field) {
            foreach ($j->db->execute('SELECT '.$field.' FROM '.$j->db->table($table))->fetchAll(\PDO::FETCH_COLUMN) as $raw) {
                self::assertIsString($raw); self::assertStringNotContainsString(Journey::INPUT,$raw);
            }
        }
        foreach ($j->db->execute('SELECT ciphertext FROM '.$j->db->table('write_payloads'))->fetchAll(\PDO::FETCH_COLUMN) as $cipher) {
            self::assertIsString($cipher); self::assertStringNotContainsString('recipient',$cipher);
        }
        $grant=$j->confirm(); $ledger=$j->ledger($grant); $request=$ledger->request();
        $wrong=new \AgenToolsPlatform\Core\Actions\Effects\LookupResult(\AgenToolsPlatform\Core\Actions\Effects\LookupStatus::Succeeded,$j->ref('wrong-operation'),
            $request->ownerNamespace,$request->requestHash,$j->ref('wrong-receipt'));
        $this->denies(fn ()=>$ledger->accept($wrong),\AgenToolsPlatform\Core\Common\IntegrityConflict::class);
        self::assertSame(1,$j->count('example_owner_writes'));
    }
}

final class Worker
{
    /** @var resource */ private $process;
    /** @var array<int,resource> */ private array $pipes;
    private bool $closed=false;
    public function __construct(string $schema,string $target)
    {
        $p=proc_open([PHP_BINARY,__DIR__.'/worker.php',$schema,$target],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if (!is_resource($p)) { throw new \RuntimeException('Write worker unavailable'); }
        $this->process=$p; $this->pipes=$pipes; stream_set_timeout($pipes[1],15);
        if ($this->line()!=='ready') { throw new \RuntimeException('Write worker not ready'); }
    }
    public function line(): string { return trim((string)fgets($this->pipes[1])); }
    public function send(string $text): void { fwrite($this->pipes[0],$text); fflush($this->pipes[0]); }
    public function go(): void { $this->send("go\n"); }
    public function alive(): bool { return proc_get_status($this->process)['running']; }
    public function kill(): void { proc_terminate($this->process,9); }
    /** @return array{exit:int,stdout:string,stderr:string} */
    public function finish(): array
    {
        fclose($this->pipes[0]); $out=stream_get_contents($this->pipes[1]); $err=stream_get_contents($this->pipes[2]); fclose($this->pipes[1]); fclose($this->pipes[2]);
        $exit=proc_close($this->process); $this->closed=true; return ['exit'=>$exit,'stdout'=>$out===false?'':$out,'stderr'=>$err===false?'':$err];
    }
    public function __destruct() { if (!$this->closed) { $this->kill(); foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } proc_close($this->process); } }
}
