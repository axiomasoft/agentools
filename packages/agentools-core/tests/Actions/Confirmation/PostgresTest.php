<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Confirmation;

require_once __DIR__.'/Fixture.php';

use AgenTools\Confirmation\ConfirmationFailure;
use AgenTools\Effects\EffectReconciliationRequired;
use AgenToolsPlatform\Core\Actions\{ActionRequest, ActionSubmission, CancelRequest};
use AgenToolsPlatform\Core\Actions\Confirmation\ConfirmedAction;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, Outcome, PayloadHash, Ref};
use AgenToolsPlatform\Examples\DirectAction\Jcs;
use AgenToolsPlatform\Postgres\Confirmation\Journal;
use PHPUnit\Framework\TestCase;

final class PostgresTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f=new Fixture(); }
    protected function tearDown(): void { if (isset($this->f)) { $this->f->close(); } }

    /**
     * @param callable():mixed $operation
     * @param class-string<\Throwable> $type
     */
    private function denies(callable $operation,string $type=\Throwable::class): \Throwable
    {
        try { $operation(); } catch (\Throwable $error) { self::assertInstanceOf($type,$error); return $error; }
        self::fail('Expected confirmation rejection');
    }

    public function testValidConsumesOnce(): void
    {
        $c=$this->f->composition;
        $preview=$this->f->approve();
        $receipt=$c->confirmation->commit($preview->token,$c->submission());
        self::assertSame($preview->binding->hash->value,$receipt->bindingHash->value);
        self::assertSame(1,$this->f->count('confirmation_consumed_tokens'));
        $this->f->owner->write($receipt,$c->submission());
        self::assertSame(1,$this->f->count('example_owner_writes'));
        self::assertSame('completed',$c->actions->status($receipt->action,$c->context())->receipt->wireStatus);
        $error=$this->denies(fn ()=>$c->confirmation->commit($preview->token,$c->submission()),ConfirmationFailure::class);
        self::assertInstanceOf(ConfirmationFailure::class,$error);
        self::assertSame('agentools.precondition.confirmation_token_spent',$error->error->code);
        self::assertSame(1,$this->f->count('example_owner_writes'));
    }

    public function testRevokeBeforeOwnerWrite(): void
    {
        $c=$this->f->composition;
        $preview=$this->f->approve();
        $receipt=$c->confirmation->commit($preview->token,$c->submission());
        $c->environment->db->execute('UPDATE '.$c->environment->db->table('example_owner_permissions').' SET allowed=false');
        $error=$this->denies(fn ()=>$this->f->owner->write($receipt,$c->submission()),ContractViolation::class);
        self::assertInstanceOf(ContractViolation::class,$error);
        self::assertSame(ErrorCode::AccessDenied,$error->errorCode);
        self::assertSame(0,$this->f->count('example_owner_writes'));
        self::assertSame('failed',$c->actions->status($receipt->action,$c->context())->receipt->wireStatus);
    }

    /** Real deferred SQL failure, after the separate owner connection committed. */
    private function loseOwnerReceipt(bool $loseAllJournalWrites=false): ConfirmedAction
    {
        $c=$this->f->composition;
        $preview=$this->f->approve();
        $grant=$c->confirmation->commit($preview->token,$c->submission());
        $pdo=$this->f->database->pdo;
        $schema='"'.$this->f->schema.'"';
        $kinds=$loseAllJournalWrites ? "'effect_confirmed','task_binding_lost'" : "'effect_confirmed'";
        $pdo->exec('CREATE FUNCTION '.$schema.'.owner_receipt_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            IF NEW.kind IN ('.$kinds.') THEN RAISE EXCEPTION \'owner receipt fault\'; END IF; RETURN NULL; END $$');
        $pdo->exec('CREATE CONSTRAINT TRIGGER owner_receipt_fault AFTER INSERT ON '.$schema.'.agentools_events
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION '.$schema.'.owner_receipt_fault()');
        try { $this->denies(fn ()=>$this->f->owner->write($grant,$c->submission()),EffectReconciliationRequired::class); }
        finally { $pdo->exec('DROP TRIGGER owner_receipt_fault ON '.$schema.'.agentools_events'); }
        self::assertSame(1,$this->f->count('example_owner_writes'));
        $db=$c->environment->db;
        self::assertSame(0,(int)$db->execute('SELECT count(*) FROM '.$db->table('agentools_events')." WHERE kind IN ('effect_confirmed','action_completed','effect_failed','action_failed')")->fetchColumn());
        return $grant;
    }

    public function testOwnerCommitJournalFailureReconcilesSameKey(): void
    {
        $grant=$this->loseOwnerReceipt();
        $fresh=new Fixture($this->f->schema);
        $c=$fresh->composition;
        $db=$c->environment->db;
        // Fail even an attempted duplicate INSERT (including ON CONFLICT). A
        // successful reconciliation must only look up the already committed row.
        $pdo=$this->f->database->pdo;
        $schema='"'.$this->f->schema.'"';
        $pdo->exec('CREATE FUNCTION '.$schema.'.owner_replay_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'owner replay forbidden\'; END $$');
        $pdo->exec('CREATE TRIGGER owner_replay_fault BEFORE INSERT OR UPDATE OR DELETE ON '.$schema.'.example_owner_writes FOR EACH ROW EXECUTE FUNCTION '.$schema.'.owner_replay_fault()');
        $view=$c->actions->status($grant->action,$c->context());
        self::assertSame('reconciliation_required',$view->receipt->wireStatus);
        self::assertSame(Outcome::Unknown,$view->outcome());
        $intent=$db->row('SELECT * FROM '.$db->table('effect_intents'));
        self::assertNotNull($intent);
        self::assertSame('unknown',$intent['outcome']);
        self::assertNull($intent['receipt_ref']);
        $operation=$intent['owner_operation_key'];
        self::assertSame($operation,$db->execute('SELECT operation_key FROM '.$db->table('example_owner_writes'))->fetchColumn());
        // The original grant cannot blindly dispatch the saved request again.
        $this->denies(fn ()=>$fresh->owner->write($grant,$c->submission()),ContractViolation::class);
        self::assertSame(1,$this->f->count('effect_intents'));
        $fresh->owner->reconcile($grant,$c->submission());
        $done=$c->actions->status($grant->action,$c->context());
        self::assertSame('completed',$done->receipt->wireStatus);
        self::assertSame(Outcome::Succeeded,$done->outcome());
        self::assertSame('none',$done->safeNextStep);
        $resolved=$db->row('SELECT * FROM '.$db->table('effect_intents'));
        self::assertNotNull($resolved);
        self::assertSame('success',$resolved['outcome']);
        self::assertSame($operation,$resolved['receipt_ref']);
        self::assertSame($intent['request_hash'],$resolved['request_hash']);
        self::assertSame($intent['claim_epoch'],$resolved['claim_epoch']);
        $fresh->owner->reconcile($grant,$c->submission());
        self::assertSame($done->receipt->version,$c->actions->status($grant->action,$c->context())->receipt->version);
        self::assertSame(1,(int)$db->execute('SELECT count(*) FROM '.$db->table('agentools_events')." WHERE kind='effect_requested'")->fetchColumn());
        self::assertSame(1,(int)$db->execute('SELECT count(*) FROM '.$db->table('agentools_events')." WHERE kind='effect_confirmed' AND epoch=:epoch",['epoch'=>$grant->epoch+1])->fetchColumn());
        self::assertSame(1,$this->f->count('example_owner_writes'));
    }

    public function testOwnerReceiptOutageRetainsApplyingUnknown(): void
    {
        $grant=$this->loseOwnerReceipt(true);
        $fresh=new Fixture($this->f->schema);
        $c=$fresh->composition;
        $view=$c->actions->status($grant->action,$c->context());
        // Exact saved F2 state remains meaningful even if journaling the loss fails.
        self::assertSame('applying',$view->receipt->wireStatus);
        self::assertSame(Outcome::Unknown,$view->outcome());
        self::assertSame('reconcile',$view->safeNextStep);
        self::assertSame('manual_resolution',(new \AgenToolsPlatform\Core\Actions\ActionView(
            $view->receipt,$view->definition,'manual_resolution'))->safeNextStep);
        self::assertSame('pending',$c->environment->db->execute('SELECT outcome FROM '.$c->environment->db->table('effect_intents'))->fetchColumn());
        $this->denies(fn ()=>$fresh->owner->write($grant,$c->submission()),ContractViolation::class);
        $fresh->owner->reconcile($grant,$c->submission());
        self::assertSame('completed',$c->actions->status($grant->action,$c->context())->receipt->wireStatus);
        self::assertSame(1,$this->f->count('example_owner_writes'));
    }

    public function testOwnerReconciliationRequiresBoundReceiptAndCurrentAuthority(): void
    {
        $grant=$this->loseOwnerReceipt();
        $fresh=new Fixture($this->f->schema);
        $c=$fresh->composition;
        $db=$c->environment->db;
        $version=$c->actions->status($grant->action,$c->context())->receipt->version;
        $db->execute('UPDATE '.$db->table('example_owner_permissions').' SET allowed=false');
        $error=$this->denies(fn ()=>$fresh->owner->reconcile($grant,$c->submission()),ContractViolation::class);
        self::assertInstanceOf(ContractViolation::class,$error);
        self::assertSame(ErrorCode::AccessDenied,$error->errorCode);
        $db->execute('UPDATE '.$db->table('example_owner_permissions').' SET allowed=true');
        $this->denies(fn ()=>$fresh->owner->reconcile($grant,$c->submission(context:$c->context('other-session'))),ContractViolation::class);
        $this->denies(fn ()=>$fresh->owner->reconcile($grant,$c->submission('{"recipient":"changed","document":"d1","amount":100}')),ContractViolation::class);
        // Moving the saved receipt away makes same-key lookup absent, not replayable.
        $db->execute('UPDATE '.$db->table('example_owner_writes')." SET operation_key='other-key'");
        $this->denies(fn ()=>$fresh->owner->reconcile($grant,$c->submission()),EffectReconciliationRequired::class);
        $db->execute('UPDATE '.$db->table('example_owner_writes').' SET operation_key=(SELECT owner_operation_key FROM '.$db->table('effect_intents').')');
        $db->execute('UPDATE '.$db->table('example_owner_writes')." SET request_hash='sha-256:".str_repeat('a',64)."'");
        $this->denies(fn ()=>$fresh->owner->reconcile($grant,$c->submission()),IntegrityConflict::class);
        $db->execute('UPDATE '.$db->table('example_owner_writes').' SET request_hash=:hash,payload=\'{"recipient":"forged","document":"d1","amount":100}\'::jsonb',
            ['hash'=>$c->submission()->request->inputHash->value]);
        $this->denies(fn ()=>$fresh->owner->reconcile($grant,$c->submission()),IntegrityConflict::class);
        $db->execute('UPDATE '.$db->table('example_owner_writes').' SET payload=CAST(:payload AS jsonb)', ['payload'=>$c->submission()->inputJson]);
        self::assertSame($version,$c->actions->status($grant->action,$c->context())->receipt->version);
        self::assertSame('unknown',$db->execute('SELECT outcome FROM '.$db->table('effect_intents'))->fetchColumn());
        $db->transaction(function () use ($c,$grant): void {
            $journal=new Journal($c->environment); $state=$journal->read($grant->action,true);
            $journal->append($grant->action,$state,[['kind'=>'epoch_started','payload'=>[],'epoch'=>$state->epoch+1]]);
        });
        $error=$this->denies(fn ()=>$fresh->owner->reconcile($grant,$c->submission()),ContractViolation::class);
        self::assertInstanceOf(ContractViolation::class,$error);
        self::assertSame(ErrorCode::StaleClaim,$error->errorCode);
        self::assertSame(0,(int)$db->execute('SELECT count(*) FROM '.$db->table('agentools_events')." WHERE kind='effect_confirmed'")->fetchColumn());
        self::assertSame(1,$this->f->count('example_owner_writes'));
    }

    public function testConcurrentConsume(): void
    {
        $c=$this->f->composition;
        $preview=$this->f->approve();
        $lock=$this->f->database->pdo;
        $lock->beginTransaction();
        $lock->exec('LOCK TABLE '.$c->environment->db->table('confirmation_consumed_tokens').' IN ACCESS EXCLUSIVE MODE');
        $workers=[];
        try {
            foreach (['a','b'] as $name) { $workers[]=new Worker($this->f->schema,'race-'.$name); }
            foreach ($workers as $worker) { $worker->go(); }
            $this->waitLocks(2);
        } finally { $lock->rollBack(); }
        $results=array_map(static fn (Worker $worker): array=>$worker->finish(),$workers);
        $success=array_values(array_filter($results,static fn (array $r): bool=>$r['exit']===0));
        self::assertCount(1,$success,json_encode($results,JSON_THROW_ON_ERROR));
        $denied=array_values(array_filter($results,static fn (array $r): bool=>$r['exit']!==0));
        self::assertCount(1,$denied);
        $loser=array_shift($denied);
        self::assertIsArray($loser);
        self::assertStringContainsString('confirmation_token_spent',$loser['stderr'],json_encode($results,JSON_THROW_ON_ERROR));
        self::assertSame(1,$this->f->count('confirmation_consumed_tokens'));
        self::assertSame(1,$this->f->count('example_owner_writes'));
    }

    public function testExactBindings(): void
    {
        $c=$this->f->composition;
        $preview=$this->f->approve();
        foreach (['{"recipient":"r2","document":"d1","amount":100}','{"recipient":"r1","document":"d2","amount":100}',
            '{"recipient":"r1","document":"d1","amount":101}'] as $input) {
            $this->denies(fn ()=>$c->confirmation->commit($preview->token,$c->submission($input)),IntegrityConflict::class);
        }
        $this->denies(fn ()=>$c->confirmation->commit($preview->token,$c->submission(context:$c->context('other-session'))),IntegrityConflict::class);
        $c->host->actor='other';
        $this->denies(fn ()=>$c->confirmation->commit($preview->token,$c->submission()),IntegrityConflict::class);
        $c->host->actor='actor';
        $c->host->revision='policy-2';
        $this->denies(fn ()=>$c->confirmation->commit($preview->token,$c->submission()),IntegrityConflict::class);
        $c->host->revision='policy-1';
        $r=$c->submission()->request;
        $ref=new \AgenToolsPlatform\Core\Profiles\DefinitionRef($r->definition->id,'2',$r->definition->hash);
        $changed=new ActionSubmission(new ActionRequest($ref,$r->originRef,null,$r->idempotencyKey,$r->inputRef,$r->inputHash,
            $r->context,$r->deadline,$c->hasher),$c->submission()->inputJson,$c->hasher,new Jcs());
        $this->denies(fn ()=>$c->confirmation->commit($preview->token,$changed),IntegrityConflict::class);
        $c->host->instant=(new \DateTimeImmutable($preview->binding->expiresAt->value))->modify('+1 microsecond');
        $expired=$this->denies(fn ()=>$c->confirmation->commit($preview->token,$c->submission()),ConfirmationFailure::class);
        self::assertStringNotContainsString($preview->token,(string)$expired);
        self::assertStringNotContainsString($preview->token,json_encode($expired->getTrace(),JSON_THROW_ON_ERROR));
        self::assertSame(0,$this->f->count('confirmation_consumed_tokens'));
        self::assertSame(0,$this->f->count('example_owner_writes'));
    }

    public function testPositiveBindingsAndVoice(): void
    {
        $c=$this->f->composition;
        $submission=$c->submission();
        $preview=$c->confirmation->prepare($submission);
        self::assertStringStartsWith('at1.',$preview->token);
        $this->denies(fn ()=>$c->confirmation->approve($preview->token,$submission),ContractViolation::class);
        $fresh=new Fixture($this->f->schema);
        $recovered=$fresh->composition->confirmation->prepare($fresh->composition->submission());
        self::assertSame($preview->token,$recovered->token);
        self::assertSame($preview->binding->toArray(),$recovered->binding->toArray());
        self::assertSame(1,$this->f->count('confirmation_previews'));
        $this->f->consent($preview,'voice');
        $c->confirmation->approve($preview->token,$submission);
        $c->confirmation->approve($preview->token,$submission);
        self::assertSame(1,$this->f->count('confirmation_proofs'));
        $receipt=$c->confirmation->commit($preview->token,$submission);
        $this->f->owner->write($receipt,$submission);
        self::assertSame(1,$this->f->count('example_owner_writes'));
        $payload=$c->environment->db->execute('SELECT payload::text FROM '.$c->environment->db->table('example_owner_writes'))->fetchColumn();
        self::assertIsString($payload);
        self::assertSame(json_decode($submission->inputJson,true),json_decode($payload,true));
    }

    public function testCrashResponseLossAndEpoch(): void
    {
        $c=$this->f->composition;
        $preview=$this->f->approve();
        $worker=new Worker($this->f->schema,'after-confirmation');
        $worker->go();
        self::assertSame('committed',$worker->line());
        $worker->kill();
        self::assertNotSame(0,$worker->finish()['exit']);
        $fresh=new Fixture($this->f->schema);
        $error=$this->denies(fn ()=>$fresh->composition->confirmation->commit($preview->token,$fresh->composition->submission()),ConfirmationFailure::class);
        self::assertInstanceOf(ConfirmationFailure::class,$error);
        self::assertSame('agentools.precondition.confirmation_token_spent',$error->error->code);
        self::assertSame(0,$this->f->count('example_owner_writes'));
        // A new bound Action is required; changing epoch invalidates its existing preview/proof.
        $submission=$c->submission(key:'epoch-action');
        $other=$c->confirmation->prepare($submission);
        $this->f->consent($other);
        $c->confirmation->approve($other->token,$submission);
        $c->environment->db->transaction(function () use ($c,$other): void {
            $journal=new Journal($c->environment); $state=$journal->read($other->binding->action,true);
            $journal->append($other->binding->action,$state,[['kind'=>'epoch_started','payload'=>[],'epoch'=>1]]);
        });
        $this->denies(fn ()=>$c->confirmation->commit($other->token,$submission),ConfirmationFailure::class);
        $lateSubmission=$c->submission(key:'owner-epoch-action');
        $late=$c->confirmation->prepare($lateSubmission); $this->f->consent($late);
        $c->confirmation->approve($late->token,$lateSubmission);
        $grant=$c->confirmation->commit($late->token,$lateSubmission);
        $c->environment->db->transaction(function () use ($c,$late): void {
            $journal=new Journal($c->environment); $state=$journal->read($late->binding->action,true);
            $journal->append($late->binding->action,$state,[['kind'=>'epoch_started','payload'=>[],'epoch'=>1]]);
        });
        $this->denies(fn ()=>$this->f->owner->write($grant,$lateSubmission),ContractViolation::class);
        self::assertSame(0,$this->f->count('example_owner_writes'));
    }

    public function testDurablePreparationRollback(): void
    {
        $c=$this->f->composition;
        $pdo=$this->f->database->pdo;
        $pdo->exec('CREATE FUNCTION "'.$this->f->schema.'".confirmation_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'fault\'; END $$');
        $pdo->exec('CREATE CONSTRAINT TRIGGER confirmation_fault AFTER INSERT ON "'.$this->f->schema.'".confirmation_previews DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "'.$this->f->schema.'".confirmation_fault()');
        try { $this->denies(fn ()=>$c->confirmation->prepare($c->submission())); }
        finally { $pdo->exec('DROP TRIGGER confirmation_fault ON "'.$this->f->schema.'".confirmation_previews'); }
        self::assertSame(0,$this->f->count('confirmation_previews'));
        self::assertSame(1,$this->f->count('agentools_events'));
        $preview=$c->confirmation->prepare($c->submission());
        $db=$c->environment->db;
        $this->denies(fn ()=>$db->execute('INSERT INTO '.$db->table('confirmation_proofs').'
            (tenant_id,id,source_ref,source_hash,binding_hash,actor_ref,channel,expires_at,journal_version)
            VALUES (\'tenant-a\',:id,\'model:claimed-yes\',:hash,:binding,\'actor\',\'voice\',:expires,3)',
            ['id'=>$preview->proposal->value,'hash'=>'sha-256:'.str_repeat('a',64),'binding'=>$preview->binding->hash->value,'expires'=>$preview->binding->expiresAt->value]),ContractViolation::class);
        self::assertSame(0,$this->f->count('confirmation_proofs'));
        self::assertSame(0,$this->f->count('example_owner_writes'));
    }

    public function testCancellationAndCompensation(): void
    {
        $c=$this->f->composition;
        $preview=$this->f->approve();
        $receipt=$c->confirmation->commit($preview->token,$c->submission());
        $request=new CancelRequest(new Ref($c->host->tenant,'cancel-before-write'),$receipt->version,$c->context());
        $cancel=$c->actions->cancel($receipt->action,$request)->view;
        self::assertSame('running',$cancel->receipt->wireStatus);
        $this->denies(fn ()=>$this->f->owner->write($receipt,$c->submission()),ContractViolation::class);
        self::assertSame(1,$this->f->count('action_cancellation_requests'));
        $c->actions->cancel($receipt->action,$request);
        self::assertSame(1,$this->f->count('action_cancellation_requests'));
        $this->denies(fn ()=>$c->actions->cancel($receipt->action,new CancelRequest($request->commandId,$cancel->receipt->version,$c->context())),IntegrityConflict::class);
        self::assertSame(0,$this->f->count('example_owner_writes'));
        $forged=new ConfirmedAction($receipt->action,new Ref($c->host->tenant,'compensation-without-new-action'),new PayloadHash('sha-256:'.str_repeat('a',64)),$receipt->version,$receipt->epoch);
        $this->denies(fn ()=>$this->f->owner->write($forged,$c->submission()),ContractViolation::class);
        $r=$c->submission()->request;
        $unregistered=new ActionSubmission(new ActionRequest(new \AgenToolsPlatform\Core\Profiles\DefinitionRef('document.compensate','1',$r->definition->hash),
            $r->originRef,null,new Ref($c->host->tenant,'unauthorized-compensation'),$r->inputRef,$r->inputHash,$r->context,$r->deadline,$c->hasher),
            $c->submission()->inputJson,$c->hasher,new Jcs());
        $this->denies(fn ()=>$c->actions->submit($unregistered),ContractViolation::class);
        $other=$c->submission(key:'complete-first'); $p=$c->confirmation->prepare($other); $this->f->consent($p);
        $c->confirmation->approve($p->token,$other); $known=$c->confirmation->commit($p->token,$other); $this->f->owner->write($known,$other);
        $current=$c->actions->status($known->action,$c->context());
        $after=$c->actions->cancel($known->action,new CancelRequest(new Ref($c->host->tenant,'cancel-known-success'),$current->receipt->version,$c->context()))->view;
        self::assertSame('completed',$after->receipt->wireStatus);
        self::assertSame($current->receipt->version,$after->receipt->version);
        self::assertSame(2,$this->f->count('action_cancellation_requests'));
    }

    private function waitLocks(int $count): void
    {
        $deadline=hrtime(true)+10_000_000_000;
        do {
            $stmt=$this->f->database->pdo->prepare("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND application_name LIKE :prefix AND wait_event_type='Lock'");
            $stmt->execute(['prefix'=>'confirmation_'.$this->f->schema.'%']); $waiting=(int)$stmt->fetchColumn();
            if ($waiting===$count) { break; } usleep(1000);
        } while (hrtime(true)<$deadline);
        self::assertSame($count,$waiting,'Workers must overlap at a real PostgreSQL barrier');
    }

    public function testRunnableExample(): void
    {
        $env=getenv();
        $env['AGENTOOLS_CONFIRMATION_SIGNING_KEY']=base64_encode(str_repeat('s',32));
        $env['AGENTOOLS_CONFIRMATION_SEAL_KEY']=base64_encode(str_repeat('e',32));
        $process=proc_open([PHP_BINARY,dirname(__DIR__,5).'/examples/platform/confirmed-write/run.php',$this->f->schema,'synthetic-ui'],
            [['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,$env);
        self::assertIsResource($process); fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$err===false?'':$err);
        self::assertIsString($out); $data=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        self::assertIsArray($data); self::assertSame('completed',$data['status']); self::assertSame('succeeded',$data['outcome']);
        self::assertSame(1,$this->f->count('example_owner_writes'));
    }
}

final class Worker
{
    /** @var resource */ private $process;
    /** @var array<int,resource> */ private array $pipes;
    private bool $closed=false;
    public function __construct(string $schema,string $mode)
    {
        $p=proc_open([PHP_BINARY,__DIR__.'/worker.php',$schema,$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if (!is_resource($p)) { throw new \RuntimeException('Worker unavailable'); }
        $this->process=$p; $this->pipes=$pipes; stream_set_timeout($pipes[1],10);
        if ($this->line()!=='ready') { throw new \RuntimeException('Confirmation worker not ready'); }
    }
    public function line(): string { return trim((string)fgets($this->pipes[1])); }
    public function go(): void { fwrite($this->pipes[0],"go\n"); fflush($this->pipes[0]); }
    public function kill(): void { proc_terminate($this->process,9); }
    /** @return array{exit:int,stdout:string,stderr:string} */
    public function finish(): array {
        fclose($this->pipes[0]); $out=stream_get_contents($this->pipes[1]); $err=stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]); fclose($this->pipes[2]); $exit=proc_close($this->process); $this->closed=true;
        return ['exit'=>$exit,'stdout'=>$out===false?'':$out,'stderr'=>$err===false?'':$err];
    }
    public function __destruct() { if (!$this->closed) { $this->kill(); foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } proc_close($this->process); } }
}
