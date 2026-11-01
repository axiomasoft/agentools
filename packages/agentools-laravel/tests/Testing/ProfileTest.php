<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Testing;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode};
use AgenToolsPlatform\Laravel\Managed\{Entry,Executor,HostBindings,Input};
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f=new Fixture(); }
    protected function tearDown(): void { $this->f->close(); }
    public function testTestingProfileKeepsRealGuardsAndConfirmation(): void
    {
        $f=$this->f;$count=0;
        $matrix=json_decode(file_get_contents(dirname(__DIR__,4).'/fixtures/platform/v1/composition/testing-profile.json')?:throw new \LogicException('Missing testing fixture'),true,512,JSON_THROW_ON_ERROR);
        self::assertIsArray($matrix);self::assertSame(array_map(static fn(Entry $entry):string=>$entry->value,Entry::cases()),$matrix['entries']);
        foreach(Entry::cases() as $entry){
            $f->base->instance='testing-'.$entry->value;
            $receipt=$f->base->app->make(Executor::class)->execute(new Input('host:testing',$entry));
            self::assertNotNull($receipt->acknowledgement);self::assertSame('alpha',$receipt->id->tenant->value);
            self::assertSame(++$count,$f->base->current()->count('confirmation_previews'));
            self::assertSame(0,$f->profile->owner->writes);self::assertTrue($f->profile->scope->isClear());
            self::assertTrue($f->profile->secrets->isClear());self::assertTrue($f->profile->payloads->isClear());
        }
        self::assertSame(7,$f->profile->transport->exchanges);self::assertSame(8,$f->profile->budget->reservations);
        self::assertSame(8,$f->profile->delivery->sent);
    }
    public function testProductionRejectsFakePersistenceAndSecrets(): void
    {
        $f=$this->f;
        foreach([$f->profile->payloads,$f->profile->secrets,$f->profile->transport,$f->profile->budget] as $fake){
            try{HostBindings::assertProductionService($fake);self::fail('Testing binding accepted in production');}
            catch(\LogicException $error){self::assertStringContainsString('Production composition',$error->getMessage());}
        }
        $f->base->app->instance('env','production');$f->base->app->instance('agentools.testing',true);
        try{$f->base->app->make(Executor::class)->execute(new Input('host:prod'));self::fail('Testing flag authorized production');}
        catch(ContractViolation $error){self::assertSame(ErrorCode::PolicyConflict,$error->errorCode);}
        self::assertSame(0,$f->profile->transport->exchanges);self::assertSame(0,$f->profile->owner->writes);
    }
    public function testRawBypassAndUnknownBudgetNeverReachModel(): void
    {
        $f=$this->f;$f->base->app->make(HostBindings::class);$j=$f->base->current();$plan=$j->plan();
        try{$f->profile->transport->generate($plan->request,$plan->deployment);self::fail('Raw call bypassed active job/admission');}
        catch(ContractViolation){}
        $f->budgetOutcome='unknown';
        try{$f->base->app->make(Executor::class)->execute(new Input('host:unknown',Entry::Raw));self::fail('Unknown budget granted IO');}
        catch(ContractViolation $error){self::assertSame(ErrorCode::AdmissionDenied,$error->errorCode);}
        self::assertSame(0,$f->profile->transport->exchanges);self::assertSame(0,$f->profile->budget->reservations);self::assertSame(0,$f->profile->delivery->sent);
        self::assertTrue($f->profile->scope->isClear());
    }
    public function testSequentialAlphaBetaAndExceptionCancelReset(): void
    {
        $f=$this->f;$f->base->app->make(Executor::class)->execute(new Input('host:alpha'));
        $alpha=$f->base->current();$f->base->tenant='beta';$f->base->instance='beta';
        $beta=$f->base->app->make(Executor::class)->execute(new Input('host:beta',Entry::Background));
        self::assertSame('beta',$beta->id->tenant->value);self::assertStringNotContainsString('alpha',json_encode($beta,JSON_THROW_ON_ERROR));
        self::assertTrue($f->profile->secrets->isClear());self::assertTrue($f->profile->history->isClear());self::assertFalse($f->profile->authority->hasCachedIdentity());
        $f->begin($f->base->current());
        try{$f->profile->secrets->resolve($alpha->ref('credential:synthetic'));self::fail('Alpha secret crossed jobs');}catch(ContractViolation){}
        $context=$f->base->current()->context;$t=$context->identity->tenant;
        $hooks=new HookDoubles($f->profile->scope,new \AgenToolsPlatform\Testing\Composition\ExternalState(),$f->profile->history);
        $reference=new \AgenToolsPlatform\Laravel\Definitions\Reference(\AgenToolsPlatform\Laravel\Definitions\OwnerScope::tenant($t),
            new \AgenToolsPlatform\Core\Profiles\DefinitionRef('test.definition','1',new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.hash('sha256','synthetic'))));
        $hooks->assertCurrent($context,$reference);
        $hooks->apply(new \AgenToolsPlatform\Laravel\Plugins\HookInput(\AgenToolsPlatform\Laravel\Plugins\Phase::ContextData,$context->session,$context->purpose,
            new \AgenToolsPlatform\Core\Common\Ref($t,'hook-input'),Entry::Raw));
        $block=$f->profile->history->read($context,new \AgenToolsPlatform\Core\Common\Ref($t,'history:beta'));
        $trusted=new \AgenToolsPlatform\Core\Security\TrustedInput($context,
            new \AgenToolsPlatform\Core\Common\SourceIdentity(new \AgenToolsPlatform\Core\Common\InstanceId($t,'assistant'),'test','hook'),
            new \AgenToolsPlatform\Core\Common\InputPayload(new \AgenToolsPlatform\Core\Common\Ref($t,'hook-input'),$block->hash));
        $hooks->accept($trusted,new \AgenToolsPlatform\Laravel\Plugins\Contribution([
            new \AgenToolsPlatform\Laravel\Plugins\Fragment($block->ref,$block->hash,1,'synthetic-history')]));
        try{$hooks->accept($trusted,new \AgenToolsPlatform\Laravel\Plugins\Contribution([
            new \AgenToolsPlatform\Laravel\Plugins\Fragment($block->ref,$reference->version->hash,1,'synthetic-bad-hash')]));self::fail('Hook accepted unchecked bytes');}
        catch(\AgenToolsPlatform\Core\Common\IntegrityConflict){}
        $hooks->revoke($context,$reference);$hooks->reset();self::assertTrue($hooks->isClear());
        try{$hooks->assertCurrent($context,$reference);self::fail('Reset erased authoritative revocation');}catch(ContractViolation){}
        $f->profile->reset();$f->base->instance='error';$f->base->trace->deny='profile/data';
        try{$f->base->app->make(Executor::class)->execute(new Input('host:error'));self::fail('Guard error ignored');}catch(ContractViolation){}
        self::assertTrue($f->profile->scope->isClear());self::assertTrue($f->profile->secrets->isClear());
        $f->base->trace->deny=null;$f->base->trace->cancelAtDeployment=true;
        $f->base->trace->cancel=fn()=>$f->base->events->dispatch(new \Laravel\Octane\Events\TickTerminated($f->base->app,$f->base->app));$f->base->instance='cancel';
        try{$f->base->app->make(Executor::class)->execute(new Input('host:cancel'));self::fail('Cancelled job ran');}
        catch(ContractViolation $error){self::assertSame(ErrorCode::Cancelled,$error->errorCode);}
        self::assertTrue($f->profile->scope->isClear());self::assertTrue($f->profile->payloads->isClear());self::assertTrue($f->profile->history->isClear());
    }
}
