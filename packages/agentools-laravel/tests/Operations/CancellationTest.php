<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Operations;

require_once __DIR__.'/Fixture.php';

use AgenToolsPlatform\Core\Actions\Cancellation\Operation;
use AgenToolsPlatform\Core\Actions\Effects\ManualEvidence;
use AgenToolsPlatform\Core\Common\{ContractViolation, IntegrityConflict, Outcome, Ref};
use AgenToolsPlatform\Laravel\Operations\Operations;
use AgenToolsPlatform\Postgres\Confirmation\Journal;
use AgenToolsPlatform\Postgres\Runtime\RunRepository;
use PHPUnit\Framework\TestCase;

final class CancellationTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f=new Fixture(); }
    protected function tearDown(): void { if (isset($this->f)) { $this->f->close(); } }
    /** @param callable():mixed $call @param class-string<\Throwable> $type */
    private function denies(callable $call,string $type=\Throwable::class): void
    {
        try { $call(); } catch (\Throwable $e) { self::assertTrue(is_a($e,$type),'Expected '.$type.', got '.$e::class); return; }
        self::fail('Expected operational denial');
    }

    public function testCancellationWithUnresolvedEffect(): void
    {
        [$grant,,$owner,$effects]=$this->f->unknown(); $c=$this->f->f->composition;
        $output=$this->f->outputIntent();
        $run=$this->f->operations->cancelRun($this->f->op('run_cancel',$this->f->run,0,0));
        self::assertSame('cancelling',$run['status']);
        $view=$c->actions->status($grant->action,$c->context());
        $cancel=$this->f->op('action_cancel',$grant->action,$view->receipt->version,1,'cancel-action');
        self::assertSame('unknown',$this->f->operations->cancelAction($cancel)['owner_outcome']);
        $stop=$this->f->op('stream_stop',$output,1,1,'stop-stream');
        $interrupted=$this->f->operations->interrupt($stop);
        self::assertSame('interrupted',$interrupted['state']); self::assertSame(1,$interrupted['acknowledged_units']);
        self::assertSame(3,$interrupted['generated_units']);
        $decision=$this->f->op('run_finalize',$this->f->run,1,1,'finalize',new Ref($c->host->tenant,'policy:cancel'));
        $this->denies(fn ()=>$this->f->operations->cancelRun($decision),ContractViolation::class);
        self::assertSame(Outcome::Unknown,$c->actions->status($grant->action,$c->context())->outcome());
        self::assertSame(1,$this->f->f->count('example_owner_writes'));
        $owner->forbidWrites=true; $effects->reconcile();
        self::assertSame(Outcome::Succeeded,$c->actions->status($grant->action,$c->context())->outcome());
        self::assertSame('cancelled',$this->f->operations->cancelRun($decision)['status']);
        self::assertSame(1,$owner->calls); self::assertSame(1,$this->f->f->count('effect_intents'));
    }

    public function testCompletedEffectSurvivesTtsStop(): void
    {
        $grant=$this->f->known(); $c=$this->f->f->composition; $output=$this->f->outputIntent();
        $version=$c->actions->status($grant->action,$c->context())->receipt->version;
        $command=$this->f->op('tts_stop',$output,1,1,'stop-tts');
        $saved=$this->f->operations->interrupt($command);
        self::assertSame($saved,$this->f->operations->interrupt($command));
        self::assertSame('tts',$saved['channel']); self::assertSame(1,$this->f->output->calls);
        $view=$c->actions->status($grant->action,$c->context());
        self::assertSame(Outcome::Succeeded,$view->outcome()); self::assertSame($version,$view->receipt->version);
        self::assertSame(1,$this->f->f->count('example_owner_writes'));
        self::assertSame('unknown',$this->f->delivery()->status($c->context(),$output->value)['state']);
    }

    public function testIndependentRunActionAndOutputCommands(): void
    {
        $grant=$this->f->known(); $c=$this->f->f->composition;
        $cancel=$this->f->op('run_cancel',$this->f->run,0,0);
        $first=$this->f->operations->cancelRun($cancel);
        self::assertSame($first,$this->f->operations->cancelRun($cancel));
        self::assertSame(0,$this->f->f->count('action_cancellation_requests'));
        $version=$c->actions->status($grant->action,$c->context())->receipt->version;
        $this->f->operations->cancelAction($this->f->op('action_cancel',$grant->action,$version,1,'explicit-action-cancel'));
        self::assertSame($version,$c->actions->status($grant->action,$c->context())->receipt->version);
        self::assertSame(1,$this->f->f->count('action_cancellation_requests'));
        $decision=$this->f->op('run_finalize',$this->f->run,1,1,'finish',new Ref($c->host->tenant,'policy:cancel'));
        self::assertSame('cancelled',$this->f->operations->cancelRun($decision)['status']);
        self::assertSame(Outcome::Succeeded,$c->actions->status($grant->action,$c->context())->outcome());
        self::assertFalse($this->f->operations->compensationStatus($grant->action,$c->context())['available']);
    }

    public function testInterruptedOutputNeverClaimsWholeDelivery(): void
    {
        [$grant,,,$effects]=$this->f->unknown(); $c=$this->f->f->composition; $target=$this->f->outputIntent();
        $badOutput=new class implements \AgenToolsPlatform\Core\Actions\Cancellation\OutputControl {
            public function stop(Operation $operation): \AgenToolsPlatform\Core\Actions\Cancellation\InterruptedOutput {
                return new \AgenToolsPlatform\Core\Actions\Cancellation\InterruptedOutput(new Ref($operation->id->tenant,'other-output'),'stream','chunks',null,null,null);
            }
        };
        $badOperations=new Operations($this->f->audit,$c->actions,$c->registry,$c->policy,$c->confirmation,$badOutput);
        $this->denies(fn ()=>$badOperations->interrupt($this->f->op('stream_stop',$target,1,1,'forged-output')),IntegrityConflict::class);
        $this->denies(fn ()=>$this->f->operations->interrupt($this->f->op('stream_stop',$target,0,1,'stale-output')),ContractViolation::class);
        $this->f->output->acknowledged=null;
        $result=$this->f->operations->interrupt($this->f->op('stream_stop',$target,1,1,'unknown-stop'));
        self::assertNull($result['acknowledged_units']); self::assertSame('unknown',$result['acknowledgement']);
        self::assertArrayNotHasKey('delivered',$result);
        self::assertSame(Outcome::Unknown,$c->actions->status($grant->action,$c->context())->outcome());
        $effects->reconcile(); $version=$c->actions->status($grant->action,$c->context())->receipt->version; $effects->reconcile();
        self::assertSame($version,$c->actions->status($grant->action,$c->context())->receipt->version);
        self::assertSame(1,$this->f->f->count('example_owner_writes'));
    }

    public function testCancellationFaultRetainsPartialDelivery(): void
    {
        [$grant,, $owner,$effects]=$this->f->unknown(); $c=$this->f->f->composition;
        $target=$this->f->outputIntent();
        $this->f->operations->cancelRun($this->f->op('run_cancel',$this->f->run,0,0));
        $stop=$this->f->op('stream_stop',$target,1,1,'partial-stop');
        $partial=$this->f->operations->interrupt($stop);
        $pdo=$this->f->f->database->pdo; $schema='"'.$this->f->f->schema.'"';
        $pdo->exec('CREATE FUNCTION '.$schema.'.late_receipt_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            IF NEW.kind=\'effect_confirmed\' THEN RAISE EXCEPTION \'late receipt lost\'; END IF; RETURN NULL; END $$');
        $pdo->exec('CREATE CONSTRAINT TRIGGER late_receipt_fault AFTER INSERT ON '.$schema.'.agentools_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION '.$schema.'.late_receipt_fault()');
        try { $this->denies(fn ()=>$effects->reconcile(),\AgenTools\Effects\EffectReconciliationRequired::class); }
        finally { $pdo->exec('DROP TRIGGER late_receipt_fault ON '.$schema.'.agentools_events'); }
        self::assertSame(Outcome::Unknown,$c->actions->status($grant->action,$c->context())->outcome());
        self::assertSame('cancelling',(new RunRepository($c->environment))->get($c->context(),$this->f->run)?->status->value);
        self::assertSame(1,$partial['acknowledged_units']); self::assertSame(3,$partial['generated_units']);
        $owner->forbidWrites=true; $effects->reconcile();
        self::assertSame($partial,$this->f->operations->interrupt($stop));
        self::assertSame(1,$owner->calls); self::assertSame(1,$this->f->f->count('example_owner_writes'));
        self::assertSame(Outcome::Succeeded,$c->actions->status($grant->action,$c->context())->outcome());
    }

    public function testAuditedManualResolutionCasAndReplay(): void
    {
        [$grant,$ledger,$owner,$effects]=$this->f->unknown(); $c=$this->f->f->composition;
        $request=$ledger->request(); $outcome=$owner->lookup($request);
        $evidence=new ManualEvidence(new Ref($c->host->tenant,'actor'),new Ref($c->host->tenant,'evidence:verified-owner'),'owner_receipt_verified',$outcome);
        $version=(new Journal($c->environment))->read($grant->action)->seq;
        $payload=Operations::resolutionHash($evidence,$c->hasher);
        $other=$c->actions->submit($c->submission(key:'other-manual-target'))->view->receipt->actionId;
        $c->environment->db->transaction(function () use ($c,$other): void {
            $journal=new Journal($c->environment); $state=$journal->read($other,true);
            $journal->append($other,$state,[['kind'=>'epoch_started','payload'=>[],'epoch'=>1]]);
        });
        $wrongTarget=$this->f->op('manual_resolve',$other,$version,1,'wrong-target',$evidence->evidence,$payload);
        $this->denies(fn ()=>$this->f->operations->resolve($wrongTarget,$effects,$evidence,$this->f->authority),IntegrityConflict::class);
        $forgedOutcome=new \AgenToolsPlatform\Core\Actions\Effects\LookupResult($outcome->status,$outcome->operation,$outcome->ownerNamespace,
            $outcome->requestHash,new Ref($c->host->tenant,'forged-owner-receipt'));
        $forgedEvidence=new ManualEvidence($evidence->operator,$evidence->evidence,$evidence->basis,$forgedOutcome);
        $forged=$this->f->op('manual_resolve',$grant->action,$version,1,'forged-receipt',$evidence->evidence,Operations::resolutionHash($forgedEvidence,$c->hasher));
        $this->denies(fn ()=>$this->f->operations->resolve($forged,$effects,$forgedEvidence,$this->f->authority),ContractViolation::class);
        self::assertNull($ledger->accepted());
        $stale=$this->f->op('manual_resolve',$grant->action,$version-1,1,'stale', $evidence->evidence,$payload,'owner_reconciled');
        $this->denies(fn ()=>$this->f->operations->resolve($stale,$effects,$evidence,$this->f->authority),ContractViolation::class);
        self::assertNull($ledger->accepted());
        $command=$this->f->op('manual_resolve',$grant->action,$version,1,'resolve',$evidence->evidence,$payload,'owner_reconciled');
        $this->f->authority->allowed=false;
        $this->denies(fn ()=>$this->f->operations->resolve($command,$effects,$evidence,$this->f->authority),ContractViolation::class);
        $this->f->authority->allowed=true;
        $result=$this->f->operations->resolve($command,$effects,$evidence,$this->f->authority);
        self::assertSame($result,$this->f->operations->resolve($command,$effects,$evidence,$this->f->authority));
        self::assertSame('succeeded',$result['outcome']); self::assertSame(1,$owner->calls);
        $db=$c->environment->db;
        $row=$db->row('SELECT payload_json::jsonb->>\'reason\' AS reason,payload_json::jsonb->>\'operation_ref\' AS operation FROM '.$db->table('agentools_events')." WHERE kind='platform.effect.manual_resolution'");
        self::assertNotNull($row); self::assertSame('owner_reconciled',$row['reason']); self::assertSame('resolve',$row['operation']);
        $changed=$this->f->op('manual_resolve',$grant->action,$version,1,'resolve',$evidence->evidence,$payload,'different_reason');
        $this->denies(fn ()=>$this->f->operations->resolve($changed,$effects,$evidence,$this->f->authority),IntegrityConflict::class);
    }

    public function testManualReceiptAtomicRollback(): void
    {
        [$grant,$ledger,$owner,$effects]=$this->f->unknown(); $c=$this->f->f->composition;
        $evidence=new ManualEvidence(new Ref($c->host->tenant,'actor'),new Ref($c->host->tenant,'evidence:verified-owner'),'owner_receipt_verified',$owner->lookup($ledger->request()));
        $version=(new Journal($c->environment))->read($grant->action)->seq;
        $command=$this->f->op('manual_resolve',$grant->action,$version,1,'atomic',$evidence->evidence,Operations::resolutionHash($evidence,$c->hasher));
        $pdo=$this->f->f->database->pdo; $schema='"'.$this->f->f->schema.'"';
        $pdo->exec('CREATE FUNCTION '.$schema.'.audit_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'audit unavailable\'; END $$');
        $pdo->exec('CREATE CONSTRAINT TRIGGER audit_fault AFTER INSERT ON '.$schema.'.operator_operation_receipts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION '.$schema.'.audit_fault()');
        try { $this->denies(fn ()=>$this->f->operations->resolve($command,$effects,$evidence,$this->f->authority)); }
        finally { $pdo->exec('DROP TRIGGER audit_fault ON '.$schema.'.operator_operation_receipts'); }
        self::assertNull($ledger->accepted()); self::assertSame(0,$this->f->f->count('operator_operation_receipts'));
        self::assertSame('pending',$this->f->operations->status($command)['state']??null);
        self::assertSame($version,(new Journal($c->environment))->read($grant->action)->seq);
        $this->f->operations->resolve($command,$effects,$evidence,$this->f->authority);
        self::assertSame('recorded',$this->f->operations->status($command)['state']??null);
        self::assertSame(1,$this->f->f->count('operator_operation_receipts')); self::assertSame(1,$owner->calls);
    }

    public function testAuthorityTenantVersionAndImmutableAudit(): void
    {
        $c=$this->f->f->composition; $command=$this->f->op('run_cancel',$this->f->run,0,0);
        $this->f->authority->allowed=false;
        $this->denies(fn ()=>$this->f->operations->cancelRun($command),ContractViolation::class);
        self::assertSame(0,$this->f->f->count('operator_operations')); $this->f->authority->allowed=true;
        $this->denies(fn ()=>$this->f->operations->cancelRun($this->f->op('run_cancel',$this->f->run,1,0,'wrong-version')),ContractViolation::class);
        $this->denies(fn ()=>$this->f->operations->cancelRun($this->f->op('run_cancel',$this->f->run,0,1,'wrong-epoch')),ContractViolation::class);
        $this->f->operations->cancelRun($command); $db=$c->environment->db;
        $this->denies(fn ()=>$db->execute('UPDATE '.$db->table('operator_operations')." SET reason='forged'"),IntegrityConflict::class);
        $this->denies(fn ()=>$db->execute('DELETE FROM '.$db->table('operator_operation_receipts')),IntegrityConflict::class);
        $this->denies(fn ()=>$db->execute('UPDATE '.$db->table('runs')." SET version=version+1,status='cancelled',terminal_code='raw_edit'"),ContractViolation::class);
        $foreign=new \AgenToolsPlatform\Core\Common\RunId(new \AgenToolsPlatform\Core\Common\TenantId('tenant-b'),'run:operator');
        $this->denies(fn ()=>$this->f->op('run_cancel',$foreign,0,0,'foreign'),ContractViolation::class);
        self::assertSame('cancelling',(new RunRepository($c->environment))->get($c->context(),$this->f->run)?->status->value);
    }

    public function testSourceClosureLeavesUnknownVisible(): void
    {
        [$grant]=$this->f->unknown(); $c=$this->f->f->composition; $this->f->outputIntent();
        $source=new Ref($c->host->tenant,'source:operator');
        $this->f->delivery()->bindInput($c->context(),new Ref($c->host->tenant,'input:operator'),$source,1);
        $this->f->delivery()->closeSource($c->context(),$source,2);
        self::assertSame('cancelling',(new RunRepository($c->environment))->get($c->context(),$this->f->run)?->status->value);
        self::assertSame(Outcome::Unknown,$c->actions->status($grant->action,$c->context())->outcome());
        self::assertSame(1,$this->f->f->count('example_owner_writes'));
    }
}
