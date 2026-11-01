<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Definitions;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, TenantId};
use AgenToolsPlatform\Laravel\Managed\{Entry, LifecycleFailure};
use AgenToolsPlatform\Laravel\Plugins\{Contribution, FailurePolicy, Fragment, HookInput, Phase, Registration, Registry};
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function testOnlySelectedCodePluginRunsAndFragmentsStayData(): void
    {
        $registrations=[];$plugins=[];
        for($i=0;$i<100;++$i){$m=Fixture::manifest('plugin.'.$i);$p=new CodePlugin('plugin.'.$i);$p->contribution=new Contribution([
            new Fragment(new Ref(new TenantId('alpha'),'fragment'),Fixture::hash(),8,'third-party:untrusted')]);
            $plugins[]=$p;$registrations[]=new Registration($m,$p);}
        $registry=new Registry($registrations,new Current());$input=Fixture::trusted();
        $request=new HookInput(Phase::ContextData,$input->context->session,$input->context->purpose,$input->payload->inputRef,Entry::Raw);
        $result=$registry->run([$registrations[42]->manifest->ref],$request,$input,16);
        self::assertCount(1,$result->contribution->fragments);self::assertSame('untrusted_data',$result->contribution->fragments[0]->trust);
        foreach($plugins as $i=>$plugin){self::assertSame($i===42?1:0,$plugin->calls);}
        self::assertSame($result->decisions,$registry->run([$registrations[42]->manifest->ref],$request,$input,16)->decisions);
    }
    public function testOptionalFailureNeverSwallowsMandatoryGuard(): void
    {
        $m=Fixture::manifest(policy:FailurePolicy::Omit);$p=new CodePlugin();$p->failure='optional';$current=new Current();
        $registry=new Registry([new Registration($m,$p)],$current);$input=Fixture::trusted();
        $request=new HookInput(Phase::ContextData,$input->context->session,$input->context->purpose,$input->payload->inputRef,Entry::Background);
        $result=$registry->run([$m->ref],$request,$input,32);self::assertSame('omitted',$result->decisions[0]['status']);
        self::assertStringNotContainsString('alpha secret',json_encode($result,JSON_THROW_ON_ERROR));
        $abort=Fixture::manifest();
        try{(new Registry([new Registration($abort,$p)],new Current()))->run([$abort->ref],$request,$input,32);self::fail('Abort policy ignored');}
        catch(ContractViolation $error){self::assertSame(ErrorCode::CapabilityUnavailable,$error->errorCode);}
        $p->failure='guard';try {$registry->run([$m->ref],$request,$input,32);self::fail('Mandatory guard swallowed');}
        catch(ContractViolation $error){self::assertSame(ErrorCode::AccessDenied,$error->errorCode);}
        $p->failure=null;$current->allowed=false;$this->expectException(ContractViolation::class);$registry->run([$m->ref],$request,$input,32);
    }
    public function testBudgetsAndTenantBoundsCannotBeRelaxed(): void
    {
        $input=Fixture::trusted();$m=Fixture::manifest(budget:4);$p=new CodePlugin();$p->contribution=new Contribution([new Fragment($input->payload->inputRef,Fixture::hash(),8,'source')]);
        $registry=new Registry([new Registration($m,$p)],new Current());$request=new HookInput(Phase::ContextData,$input->context->session,$input->context->purpose,$input->payload->inputRef,Entry::Build);
        try{$registry->run([$m->ref],$request,$input,32);self::fail('Manifest budget bypassed');}catch(ContractViolation $e){self::assertSame(ErrorCode::AdmissionDenied,$e->errorCode);}
        $p->contribution=new Contribution([new Fragment(new Ref(new TenantId('beta'),'foreign'),Fixture::hash(),1,'source')]);
        try{$registry->run([$m->ref],$request,$input,32);self::fail('Foreign fragment accepted');}catch(ContractViolation){}
        $p->contribution=new Contribution([new Fragment($input->payload->inputRef,Fixture::hash(),3,'source')]);
        try{$registry->run([$m->ref],$request,$input,2);self::fail('Total budget bypassed');}catch(ContractViolation $e){self::assertSame(ErrorCode::AdmissionDenied,$e->errorCode);}
        $p->badPhase=true;
        $checked=new HookInput(Phase::CheckedDisclosure,$input->context->session,$input->context->purpose,$input->payload->inputRef,null,$input->payload->inputRef);
        try{$registry->run([$m->ref],$checked,$input,0);self::fail('Disclosure observer returned context');}catch(ContractViolation $e){self::assertSame(ErrorCode::PolicyConflict,$e->errorCode);}
        $wrong=new \AgenToolsPlatform\Laravel\Definitions\Reference($m->ref->owner,new \AgenToolsPlatform\Core\Profiles\DefinitionRef($m->ref->version->id,'1',new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.str_repeat('0',64))));
        $this->expectException(\AgenToolsPlatform\Core\Common\IntegrityConflict::class);$registry->run([$wrong],$request,$input,32);
    }
    public function testResetAttemptsEveryPluginAndErasesState(): void
    {
        $a=new CodePlugin('plugin.a');$b=new CodePlugin('plugin.b');$a->cache='alpha';$b->cache='alpha';$a->resetFailure=true;
        $current=new Current();$current->cache='alpha';
        $registry=new Registry([new Registration(Fixture::manifest('plugin.a'),$a),new Registration(Fixture::manifest('plugin.b'),$b)],$current);
        try{$registry->reset();self::fail('Reset failure hidden');}catch(LifecycleFailure){}
        self::assertSame(1,$a->resets);self::assertSame(1,$b->resets);self::assertNull($a->cache);self::assertNull($b->cache);self::assertNull($current->cache);self::assertSame(1,$current->resets);
    }
}
