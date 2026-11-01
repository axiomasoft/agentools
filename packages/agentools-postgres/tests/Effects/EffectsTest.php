<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Effects;

require_once dirname(__DIR__,3).'/agentools-core/tests/Actions/Confirmation/Fixture.php';
require_once __DIR__.'/Owner.php';

use AgenTools\Effects\EffectReconciliationRequired;
use AgenToolsPlatform\Core\Actions\Confirmation\ConfirmedAction;
use AgenToolsPlatform\Core\Actions\Effects\{LookupStatus, ManualAuthority, ManualEvidence, Request, Strategy};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, Outcome, PayloadHash, Ref};
use AgenToolsPlatform\Postgres\Actions\{ActionRepository, Projection};
use AgenToolsPlatform\Postgres\Confirmation\Journal;
use AgenToolsPlatform\Postgres\Effects\{Ledger, Service};
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Tests\Confirmation\Fixture;
use PHPUnit\Framework\TestCase;

final class EffectsTest extends TestCase
{
    private Fixture $f;
    
    protected function tearDown(): void { if (isset($this->f)) { $this->f->close(); } }

    /** @return array{ConfirmedAction,Ledger,Owner,Service} */
    private function setupEffect(Strategy $strategy=Strategy::NativeKey): array
    {
        $definition=new \AgenToolsPlatform\Core\Actions\ActionDefinition('document.write','1','documents.owner',
            \AgenToolsPlatform\Examples\ConfirmedWrite\Composition::INPUT,'{"type":"object"}',\AgenToolsPlatform\Core\Actions\EffectClass::Write,
            ['documents.write'],true,1000,3,1000,3000,$strategy===Strategy::Unsupported?'none':$strategy->value,null,
            'tenant-region','technical-ref-only','omit-payload',['actor','outcome'],null,new \AgenToolsPlatform\Examples\DirectAction\Jcs());
        $this->f=new Fixture(null,$definition);
        $c=$this->f->composition;
        $preview=$this->f->approve();
        $grant=$c->confirmation->commit($preview->token,$c->submission());
        $ledger=new Ledger($c->environment,$grant,$c->submission(),$c->registry,$c->policy);
        $owner=new Owner(new Connection((new IsolatedDatabase())->pdo,$this->f->schema),$c->environment->db,$strategy);
        return [$grant,$ledger,$owner,new Service($ledger,$owner)];
    }

    /** @param callable():mixed $call @param class-string<\Throwable> $type */
    private function rejects(callable $call,string $type=\Throwable::class): \Throwable
    {
        try { $call(); } catch (\Throwable $e) { self::assertTrue(is_a($e,$type),'Expected '.$type.', got '.$e::class); return $e; }
        self::fail('Expected denial');
    }

    public function testOwnerCommitThenLocalJournalFailure(): void
    {
        [$grant,$ledger,$owner,$service]=$this->setupEffect();
        $pdo=$this->f->database->pdo; $schema='"'.$this->f->schema.'"';
        $pdo->exec('CREATE FUNCTION '.$schema.'.receipt_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            IF NEW.kind=\'effect_confirmed\' THEN RAISE EXCEPTION \'fault-after-owner-commit\'; END IF; RETURN NULL; END $$');
        $pdo->exec('CREATE CONSTRAINT TRIGGER receipt_fault AFTER INSERT ON '.$schema.'.agentools_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION '.$schema.'.receipt_fault()');
        try { $this->rejects(fn ()=>$service->execute(),EffectReconciliationRequired::class); }
        finally { $pdo->exec('DROP TRIGGER receipt_fault ON '.$schema.'.agentools_events'); }
        $c=$this->f->composition; $db=$c->environment->db;
        self::assertSame(1,$this->f->count('example_owner_writes'));
        self::assertSame('unknown',$db->execute('SELECT outcome FROM '.$db->table('effect_intents'))->fetchColumn());
        $queue=new \AgenToolsPlatform\Postgres\Effects\UnresolvedQueue($this->f->composition->environment);
        self::assertCount(1,$queue->candidates($this->f->composition->context()));
        self::assertCount(0,$queue->candidates($this->f->composition->context('other-session')));
        $this->rejects(fn ()=>$queue->candidates($this->f->composition->context(),101),\InvalidArgumentException::class);
        $view=$c->actions->status($grant->action,$c->context());
        self::assertSame('reconciliation_required',$view->receipt->wireStatus);
        self::assertSame(Outcome::Unknown,$view->outcome());
        $pdo->exec('CREATE FUNCTION '.$schema.'.no_replay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'no owner writes\'; END $$');
        $pdo->exec('CREATE TRIGGER no_replay BEFORE INSERT OR UPDATE OR DELETE ON '.$schema.'.example_owner_writes FOR EACH ROW EXECUTE FUNCTION '.$schema.'.no_replay()');
        $fresh=new Fixture($this->f->schema,$c->definition);
        $recovery=new Service(new Ledger($fresh->composition->environment,$grant,$fresh->composition->submission(),$fresh->composition->registry,$fresh->composition->policy),$owner);
        $recovery->reconcile(); $recovery->reconcile();
        self::assertSame(1,$owner->calls); self::assertSame(1,$owner->lookups);
        self::assertSame(1,$this->f->count('example_owner_writes'));
        self::assertSame(1,(int)$db->execute('SELECT count(*) FROM '.$db->table('agentools_events')." WHERE kind='effect_confirmed'")->fetchColumn());
        self::assertSame(Outcome::Succeeded,$c->actions->status($grant->action,$c->context())->outcome());
        self::assertNotNull($ledger->accepted());
    }

    public function testUnsupportedTimeoutNeverReplays(): void
    {
        [$grant,$ledger,$owner,$service]=$this->setupEffect(Strategy::Unsupported);
        $this->rejects(fn ()=>$service->execute(),ContractViolation::class);
        self::assertSame(0,$this->f->count('effect_intents')); self::assertSame(0,$owner->calls);
        // A pre-existing externally ambiguous operation from an unsupported adapter.
        $ledger->prepare($owner->namespace(),Strategy::Unsupported); $ledger->unknown('owner_timeout');
        $this->rejects(fn ()=>$service->reconcile(),EffectReconciliationRequired::class);
        $this->rejects(fn ()=>$service->reconcile(true),ContractViolation::class);
        self::assertSame(0,$this->f->count('example_owner_writes'));
        $db=$this->f->composition->environment->db;
        self::assertSame('unknown',$db->execute('SELECT outcome FROM '.$db->table('effect_intents'))->fetchColumn());
        self::assertSame('retained',$db->execute('SELECT payload_json::jsonb->>\'reservation_disposition\' FROM '.$db->table('agentools_events')." WHERE kind='platform.effect.unresolved' LIMIT 1")->fetchColumn());
        self::assertSame('reconciliation_required',$this->f->composition->actions->status($grant->action,$this->f->composition->context())->receipt->wireStatus);
    }

    public function testNativeKeyHashAndImmutableOutcome(): void
    {
        [, $ledger,$owner,$service]=$this->setupEffect();
        $service->execute(); $request=$ledger->request();
        self::assertEquals($owner->execute($request),$owner->execute($request));
        self::assertSame(1,$this->f->count('example_owner_writes'));
        $wrong=new Request($request->action,$request->effectKey,$request->ownerNamespace,$request->operationKey,
            new PayloadHash('sha-256:'.str_repeat('b',64)),$request->requestRef,$request->context,$request->requestedEpoch,$request->strategy);
        $this->rejects(fn ()=>$owner->execute($wrong),IntegrityConflict::class);
        $this->rejects(fn ()=>$ledger->accept($owner->result($request,LookupStatus::Failed)),IntegrityConflict::class);
        $db=$this->f->composition->environment->db;
        $this->rejects(fn ()=>$db->execute('UPDATE '.$db->table('effect_intents')." SET version=version+1,outcome='failed'"),IntegrityConflict::class);
        $this->rejects(fn ()=>$db->execute('UPDATE '.$db->table('effect_intents')." SET version=version+1,receipt_ref='forged'"),IntegrityConflict::class);
        self::assertSame('success',$db->execute('SELECT outcome FROM '.$db->table('effect_intents'))->fetchColumn());
    }

    public function testAuthenticatedManualResolution(): void
    {
        [, $ledger,$owner,$service]=$this->setupEffect(Strategy::Unsupported);
        $request=$ledger->prepare($owner->namespace(),Strategy::Unsupported); $ledger->unknown('unsupported_timeout');
        $evidence=new ManualEvidence(new Ref($request->action->tenant,'operator:verified'),new Ref($request->action->tenant,'evidence:owner-audit'),
            'owner_signed_receipt_verified',$owner->result($request,LookupStatus::Succeeded));
        $authority=new class implements ManualAuthority {
            public bool $allowed=false;
            public function authorize(Request $request,ManualEvidence $evidence): void {
                if (!$this->allowed || $evidence->operator->value!=='operator:verified' || $evidence->evidence->value!=='evidence:owner-audit') {
                    throw new ContractViolation(ErrorCode::AccessDenied);
                }
            }
        };
        $version=(new Journal($this->f->composition->environment))->read($request->action)->seq;
        $this->rejects(fn ()=>$service->resolve($evidence,$authority,$version),ContractViolation::class);
        self::assertNull($ledger->accepted());
        $authority->allowed=true; $service->resolve($evidence,$authority,$version);
        self::assertSame(0,$owner->calls);
        $db=$this->f->composition->environment->db;
        $row=$db->row('SELECT payload_json::jsonb->>\'operator_ref\' AS operator,payload_json::jsonb->>\'evidence_ref\' AS evidence
            FROM '.$db->table('agentools_events')." WHERE kind='platform.effect.manual_resolution'");
        self::assertNotNull($row); self::assertSame('operator:verified',$row['operator']); self::assertSame('evidence:owner-audit',$row['evidence']);
        $this->rejects(fn ()=>new ManualEvidence($evidence->operator,$evidence->evidence,'',$evidence->outcome),\InvalidArgumentException::class);
        $this->rejects(fn ()=>new ManualEvidence($evidence->operator,$evidence->evidence,'raw evidence payload',$evidence->outcome),\InvalidArgumentException::class);
    }

    public function testCurrentEpochLookupAndStaleWorker(): void
    {
        [$grant,$ledger,$owner,$service]=$this->setupEffect();
        $owner->loseResponse=true;
        $this->rejects(fn ()=>$service->execute(),EffectReconciliationRequired::class);
        $request=$ledger->request(); $new=$ledger->takeover();
        $this->rejects(fn ()=>$ledger->accept($owner->lookup($request)),ContractViolation::class);
        self::assertSame($request->operationKey,$new->request()->operationKey);
        self::assertSame($request->requestedEpoch,$new->request()->requestedEpoch);
        $owner->forbidWrites=true;
        $recovery=new Service($new,$owner); $recovery->reconcile(); $recovery->reconcile();
        self::assertSame(1,$owner->calls); self::assertSame(1,$this->f->count('example_owner_writes'));
        $db=$this->f->composition->environment->db;
        self::assertSame($new->epoch,(int)$db->execute('SELECT epoch FROM '.$db->table('agentools_events')." WHERE kind='effect_confirmed'")->fetchColumn());
        self::assertSame(Outcome::Succeeded,$this->f->composition->actions->status($grant->action,$this->f->composition->context())->outcome());
    }

    public function testCommittedIntentAndReadBeforeWrite(): void
    {
        [, $ledger,$owner,$service]=$this->setupEffect(Strategy::ReadBeforeWrite);
        $result=$service->execute();
        self::assertSame(LookupStatus::Succeeded,$result->status);
        self::assertSame(1,$owner->lookups); self::assertSame(1,$owner->calls);
        self::assertSame(Strategy::ReadBeforeWrite,$ledger->request()->strategy);
        self::assertSame(1,$this->f->count('effect_intents'));
    }

    public function testUnknownAndWrongBindingDenyReplay(): void
    {
        [, $ledger,$owner,$service]=$this->setupEffect(Strategy::ReadBeforeWrite);
        $owner->lookupStatus=LookupStatus::Unknown;
        $this->rejects(fn ()=>$service->execute(),EffectReconciliationRequired::class);
        $this->rejects(fn ()=>$service->reconcile(true),ContractViolation::class);
        self::assertSame(0,$owner->calls);
        $request=$ledger->request();
        $wrong=new \AgenToolsPlatform\Core\Actions\Effects\LookupResult(LookupStatus::Succeeded,new Ref($request->action->tenant,'other-operation'),
            $request->ownerNamespace,$request->requestHash,new Ref($request->action->tenant,'wrong-receipt'));
        $this->rejects(fn ()=>$ledger->accept($wrong),IntegrityConflict::class);
        self::assertNull($ledger->accepted());
    }

    public function testResponseLossRetainsUnknownAndKnownFailure(): void
    {
        [$grant,$ledger,$owner,$service]=$this->setupEffect();
        $owner->executeStatus=LookupStatus::Failed; $owner->loseResponse=true;
        $this->rejects(fn ()=>$service->execute(),EffectReconciliationRequired::class);
        self::assertSame(Outcome::Unknown,$this->f->composition->actions->status($grant->action,$this->f->composition->context())->outcome());
        $owner->forbidWrites=true;
        self::assertSame(LookupStatus::Failed,$service->reconcile()->status);
        self::assertSame(Outcome::Failed,$this->f->composition->actions->status($grant->action,$this->f->composition->context())->outcome());
        self::assertNotNull($ledger->accepted()); self::assertSame(1,$owner->calls);
    }

    public function testRetryBoundsAndCancellation(): void
    {
        [$grant,$ledger,$owner,$service]=$this->setupEffect();
        $request=$ledger->prepare($owner->namespace(),$owner->strategy());
        $this->rejects(fn ()=>$ledger->reserveRetry($owner->result($request,LookupStatus::Unknown)),ContractViolation::class);
        $this->f->composition->environment->db->execute('SELECT pg_sleep(1.01)');
        $ledger->reserveRetry($owner->result($request,LookupStatus::Unknown));
        $this->rejects(fn ()=>$ledger->reserveRetry($owner->result($request,LookupStatus::Unknown)),ContractViolation::class);
        $db=$this->f->composition->environment->db;
        // The assertion above proves the actual DB-clock backoff, not a ManualClock.
        $db->execute('UPDATE '.$db->table('effect_intents')." SET version=version+1,retry_after=clock_timestamp()-interval '1 second'");
        $ledger->reserveRetry($owner->result($request,LookupStatus::Unknown));
        $this->rejects(fn ()=>$ledger->reserveRetry($owner->result($request,LookupStatus::Unknown)),ContractViolation::class);
        self::assertSame(3,(int)$db->execute('SELECT attempt_count FROM '.$db->table('effect_intents'))->fetchColumn());
        $db->transaction(function () use ($grant): void {
            $journal=new Journal($this->f->composition->environment); $state=$journal->read($grant->action,true);
            $journal->append($grant->action,$state,[['kind'=>'cancellation_requested','payload'=>[]]]);
        });
        $this->rejects(fn ()=>$service->reconcile(true),ContractViolation::class);
        self::assertSame(0,$owner->calls);
    }

    public function testNativeUnknownRetryUsesSameKey(): void
    {
        [, $ledger,$owner,$service]=$this->setupEffect();
        $owner->loseResponse=true;
        $this->rejects(fn ()=>$service->execute(),EffectReconciliationRequired::class);
        $request=$ledger->request();
        $owner->loseResponse=false; $owner->lookupStatus=LookupStatus::Unknown;
        $this->f->composition->environment->db->execute('SELECT pg_sleep(1.01)');
        self::assertSame(LookupStatus::Succeeded,$service->reconcile(true)->status);
        self::assertSame(2,$owner->calls); self::assertSame(1,$owner->lookups);
        self::assertSame(1,$this->f->count('example_owner_writes'));
        $db=$this->f->composition->environment->db;
        self::assertSame($request->operationKey,$db->execute('SELECT operation_key FROM '.$db->table('example_owner_writes'))->fetchColumn());
        self::assertSame(2,(int)$db->execute('SELECT attempt_count FROM '.$db->table('effect_intents'))->fetchColumn());
    }

    public function testReadAbsentRecoveryAndCapabilityBinding(): void
    {
        [, $ledger,$owner,$service]=$this->setupEffect(Strategy::ReadBeforeWrite);
        $request=$ledger->prepare($owner->namespace(),Strategy::ReadBeforeWrite);
        $wrong=new Owner(new Connection((new IsolatedDatabase())->pdo,$this->f->schema),$this->f->composition->environment->db,Strategy::NativeKey);
        $this->rejects(fn ()=>(new Service($ledger,$wrong))->reconcile(true),IntegrityConflict::class);
        self::assertSame(0,$wrong->calls); self::assertSame(0,$wrong->lookups);
        $this->f->composition->environment->db->execute('SELECT pg_sleep(1.01)');
        self::assertSame(LookupStatus::Succeeded,$service->reconcile(true)->status);
        self::assertSame(1,$owner->calls); self::assertSame(1,$owner->lookups);
        self::assertSame($request->operationKey,$ledger->request()->operationKey);
        self::assertSame(1,$this->f->count('example_owner_writes'));
    }

    public function testDefinitionLimitAndCurrentEmergencyPolicy(): void
    {
        $this->f=new Fixture(); $c=$this->f->composition;
        $p=$this->f->approve(); $grant=$c->confirmation->commit($p->token,$c->submission());
        $ledger=new Ledger($c->environment,$grant,$c->submission(),$c->registry,$c->policy);
        $owner=new Owner(new Connection((new IsolatedDatabase())->pdo,$this->f->schema),$c->environment->db);
        $service=new Service($ledger,$owner); $owner->loseResponse=true;
        $this->rejects(fn ()=>$service->execute(),EffectReconciliationRequired::class);
        $owner->lookupStatus=LookupStatus::Unknown;
        $c->environment->db->execute('SELECT pg_sleep(1.01)');
        $this->rejects(fn ()=>$service->reconcile(true),ContractViolation::class);
        self::assertSame(1,$owner->calls); // Example definition pins maxAttempts=1.
        $policy=new class implements \AgenToolsPlatform\Core\Actions\ActionPolicy {
            public function current(\AgenToolsPlatform\Core\Security\ExecutionContext $context): \AgenToolsPlatform\Core\Actions\ActionAccess {
                return new \AgenToolsPlatform\Core\Actions\ActionAccess([],[],[],[],[],[]);
            }
        };
        $denied=new Service(new Ledger($c->environment,$grant,$c->submission(),$c->registry,$policy),$owner);
        $before=$owner->lookups;
        $this->rejects(fn ()=>$denied->reconcile(),ContractViolation::class);
        self::assertSame($before,$owner->lookups); self::assertSame(1,$this->f->count('example_owner_writes'));
    }

    public function testProjectionRebuildAndCurrentAuthority(): void
    {
        [$grant,$ledger,$owner,$service]=$this->setupEffect();
        $owner->loseResponse=true; $this->rejects(fn ()=>$service->execute(),EffectReconciliationRequired::class);
        $c=$this->f->composition; $db=$c->environment->db;
        $digest=(new Projection((new Journal($c->environment))->read($grant->action)))->digest();
        $db->execute('DELETE FROM '.$db->table('action_current'));
        $this->rejects(fn ()=>$ledger->request(),IntegrityConflict::class);
        $this->rejects(fn ()=>(new ActionRepository($c->environment))->rebuild($c->context('other-session'),$grant->action,$digest),ContractViolation::class);
        $this->rejects(fn ()=>(new ActionRepository($c->environment))->rebuild($c->context(),$grant->action,str_repeat('a',64)),IntegrityConflict::class);
        (new ActionRepository($c->environment))->rebuild($c->context(),$grant->action,$digest);
        $this->rejects(fn ()=>(new Ledger($c->environment,$grant,$c->submission(context:$c->context('other-session')),$c->registry,$c->policy))->request(),ContractViolation::class);
        $db->execute('UPDATE '.$db->table('example_owner_permissions').' SET allowed=false');
        $this->rejects(fn ()=>$service->reconcile(),ContractViolation::class);
        self::assertNull($ledger->accepted());
        $db->execute('UPDATE '.$db->table('example_owner_permissions').' SET allowed=true');
        $service->reconcile(); self::assertSame(1,$this->f->count('example_owner_writes'));
    }
}
