<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Testing;

use AgenTools\Effects\EffectReconciliationRequired;
use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref};
use AgenToolsPlatform\Core\Runtime\ExchangeResult;
use AgenToolsPlatform\Core\Actions\{ActionRequest,ActionSubmission};
use AgenToolsPlatform\Core\Actions\Confirmation\Decision;
use AgenToolsPlatform\Core\Actions\Effects\LookupStatus;
use AgenToolsPlatform\Laravel\Managed\{Executor,Input};
use AgenToolsPlatform\Postgres\Effects\{Ledger,Service};
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Testing\FaultPoint;
use PHPUnit\Framework\TestCase;

final class FaultTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f=new Fixture(); }
    protected function tearDown(): void { $this->f->close(); }
    public function testBeforeIntakeCommitRollsBackAndRetryRecovers(): void
    {
        $f=$this->f;$db=new Connection($f->base->database->pdo,$f->base->schema);$schema=$f->base->schema;
        $db->execute('CREATE FUNCTION "'.$schema.'".intake_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'injected\' USING ERRCODE=\'40001\'; END $$');
        $db->execute('CREATE CONSTRAINT TRIGGER intake_fault AFTER INSERT ON '.$db->table('turns').' DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "'.$schema.'".intake_fault()');
        try{$f->base->app->make(Executor::class)->execute(new Input('host:before'));self::fail('Commit fault ignored');}catch(ContractViolation){}
        self::assertSame(0,$f->base->current()->count('turns'));self::assertSame(0,$f->base->current()->count('runs'));
        self::assertSame(0,$f->profile->transport->exchanges);self::assertTrue($f->profile->scope->isClear());
        $db->execute('DROP TRIGGER intake_fault ON '.$db->table('turns'));
        $f->base->app->make(Executor::class)->execute(new Input('host:before'));
        self::assertSame(1,$f->base->current()->count('turns'));self::assertSame(1,$f->profile->transport->exchanges);
    }
    public function testAfterIntakeCommitKeepsStableAcceptedSource(): void
    {
        $f=$this->f;$f->afterIntake=true;$f->durableFaults->arm(FaultPoint::AfterCommit);
        try{$f->base->app->make(Executor::class)->execute(new Input('host:after'));self::fail('After-commit fault ignored');}catch(ContractViolation){}
        self::assertSame(1,$f->base->current()->count('turns'));$run=$f->base->current()->run->value;
        self::assertSame(0,$f->profile->transport->exchanges);$f->durableFaults->assertConsumed();
        $f->base->app->make(Executor::class)->execute(new Input('host:after'));
        self::assertSame($run,$f->base->current()->run->value);self::assertSame(1,$f->base->current()->count('turns'));self::assertSame(1,$f->profile->transport->exchanges);
    }
    public function testModelLostReplyUsesOriginalLookupWithoutResend(): void
    {
        $f=$this->f;$f->profile->transport->faults->arm(FaultPoint::AfterExternal);
        try{$f->base->app->make(Executor::class)->execute(new Input('host:model'));self::fail('Lost model reply fabricated success');}
        catch(ContractViolation $e){self::assertSame(ErrorCode::InvocationUnknown,$e->errorCode);}
        self::assertSame(1,$f->profile->transport->exchanges);self::assertSame(0,$f->profile->delivery->sent);
        $f->base->current()->expire();$f->base->app->make(Executor::class)->execute(new Input('host:model'));
        self::assertSame(1,$f->profile->transport->exchanges);self::assertSame(1,$f->profile->budget->reservations);
        self::assertSame(1,$f->base->current()->count('action_submissions'));$f->profile->transport->faults->assertConsumed();
    }
    public function testFakeOwnerNeedsRealConfirmationAndRecoversUnknown(): void
    {
        $f=$this->f;$f->base->app->make(Executor::class)->execute(new Input('host:owner'));$j=$f->base->current();$f->begin($j);
        $row=$j->db->row('SELECT proposal_ref FROM '.$j->db->table('proposal_map').' WHERE tenant_id=:tenant AND run_id=:run',
            ['tenant'=>$j->tenant->value,'run'=>$j->run->value])??throw new \LogicException('No proposal');
        $result=ExchangeResult::decode($j->plan(),$f->profile->payloads->get($j->context,$j->ref(Connection::text($row,'proposal_ref'))),$j->hasher);
        $proposal=$result->proposals[0]??throw new \LogicException('No normalized proposal');
        $submission=new ActionSubmission(new ActionRequest($proposal->definition,$j->ref($proposal->id->value),$proposal->id,$j->ref($proposal->id->value),
            $j->ref('proposal-input:'.$proposal->id->value),$proposal->inputHash,$j->context,$proposal->deadline,$j->hasher),$proposal->argumentsJson,$j->hasher,new \AgenToolsPlatform\Examples\DirectAction\Jcs());
        $raw=new \AgenToolsPlatform\Core\Actions\Effects\Request($proposal->actionId,'effect',$f->profile->owner->namespace(),'raw-operation',
            $submission->request->inputHash,$submission->request->inputRef,$j->context,1,\AgenToolsPlatform\Core\Actions\Effects\Strategy::NativeKey);
        try{$f->profile->owner->execute($raw);self::fail('Fake bypassed confirmation');}catch(ContractViolation $e){self::assertSame(ErrorCode::ConfirmationRequired,$e->errorCode);}
        self::assertSame(0,$f->profile->owner->writes);
        $gateway=$j->composition->confirmation;$preview=$gateway->prepare($submission);
        $j->composition->host->consent=new Decision($j->ref('ui:approval'),$j->hasher->hash($j->tenant,'ui-proof',$preview->binding->hash->value),
            $j->context->identity->actorRef,$preview->binding->hash,'ui',$preview->binding->expiresAt);
        $gateway->approve($preview->token,$submission);$grant=$gateway->commit($preview->token,$submission);
        $ledger=new Ledger($j->environment,$grant,$submission,$j->composition->registry,$j->policy);$f->boundary->ledger=$ledger;
        $service=new Service($ledger,$f->profile->owner);$f->profile->owner->faults->arm(FaultPoint::AfterExternal);
        try{$service->execute();self::fail('Unknown owner reply became success');}catch(EffectReconciliationRequired){}
        self::assertSame(1,$f->profile->owner->writes);self::assertNull($ledger->accepted());
        $known=$service->reconcile();self::assertSame(LookupStatus::Succeeded,$known->status);self::assertSame(1,$f->profile->owner->writes);
        self::assertSame('succeeded',$j->actions->status($grant->action,$j->context)->outcome()?->value);
        $f->profile->owner->faults->assertConsumed();$f->profile->reset();self::assertNull($f->boundary->ledger);
    }
}
