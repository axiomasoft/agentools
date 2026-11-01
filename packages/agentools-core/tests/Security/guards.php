<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,IntegrityConflict,Ref,TenantId,UtcInstant};
use AgenToolsPlatform\Core\Context\{CompiledContext,ContextSnapshot,GuardSnapshot,SourceKind};
use AgenToolsPlatform\Core\Models\{JsonObject,ToolDefinition};
use AgenToolsPlatform\Core\Security\{AccessState,BoundaryStage,EntryPoint,ToolPin};
use AgenToolsPlatform\Tests\Context\Fixture as Values;
use AgenToolsPlatform\Tests\Security\{Fixture,Proof};
use AgenToolsPlatform\Tests\Harness as H;

Proof::run([
    'security.injection-surfaces'=>static function(): void {
        $surfaces=[SourceKind::History,SourceKind::Retrieval,SourceKind::Tool,SourceKind::Summary,SourceKind::Memory,SourceKind::Skill,SourceKind::Input];
        foreach ($surfaces as $surface) {
            $f=new Fixture(sourceKind:$surface);$response=$f->wrapped()->generate($f->request,$f->deployment);
            H::check($f->transport->calls===1 && $f->plan->blocks[1]->trust==='untrusted');
            H::check($f->request->policy==='mandatory guards' && count($f->request->tools)===1 && $f->request->tools[0]->name==='search');
            $admin=new ToolPin('admin','admin','v1',$f->core->bundle->hash,$f->policy->tools[0]->schemaHash);
            H::rejects(static fn()=> $f->guard->check($f->core->context,$f->snapshot,EntryPoint::Text,BoundaryStage::Tool,[$admin]),ContractViolation::class,ErrorCode::AccessDenied);
            H::check($response->usage->inputTokens===null && $response->usage->outputTokens===null);
        }
        Proof::$observations['injection_surfaces']=['surfaces'=>array_map(static fn(SourceKind $s): string=>$s->value,$surfaces),'unexpected_tools'=>0,'policy_disabled'=>false];
    },
    'security.stale-revision'=>static function(): void {
        $f=new Fixture();$f->policy->revision=2;
        H::rejects(static fn()=> $f->wrapped()->generate($f->request,$f->deployment),ContractViolation::class,ErrorCode::StaleContext);
        H::check($f->transport->calls===0);$f=new Fixture();$f->host->revision='policy-2';
        H::rejects(static fn()=> $f->wrapped()->generate($f->request,$f->deployment),ContractViolation::class,ErrorCode::StaleContext);
        H::check($f->transport->calls===0);
    },
    'security.current-source-revoke'=>static function(): void {
        foreach (['content:data','message:source','bundle','skill:qualified','capabilities:v1'] as $ref) {
            $f=new Fixture();$f->policy->denied=[$ref];
            H::rejects(static fn()=> $f->wrapped()->generate($f->request,$f->deployment),ContractViolation::class,ErrorCode::AccessDenied);
            H::check($f->transport->calls===0);
        }
        $f=new Fixture();$f->policy->afterSource=static function() use($f): void { $f->policy->emergency=true; };
        H::rejects(static fn()=> $f->wrapped()->generate($f->request,$f->deployment),ContractViolation::class,ErrorCode::DeploymentUnavailable);
        H::check($f->transport->calls===0);
        $f=new Fixture();$f->policy->time=new UtcInstant('2030-01-01T00:00:00Z');
        H::rejects(static fn()=> $f->guard->check($f->core->context,$f->snapshot,EntryPoint::Text,BoundaryStage::Invocation),ContractViolation::class,ErrorCode::StaleContext);
    },
    'security.entry-parity'=>static function(): void {
        foreach (EntryPoint::cases() as $entry) {
            $f=new Fixture();$transport=$f->wrapped($entry);
            if (in_array($entry,[EntryPoint::Stream,EntryPoint::Voice],true)) { H::check(count(iterator_to_array($transport->stream($f->request,$f->deployment)))===3); }
            else { $transport->generate($f->request,$f->deployment); }
            $f->policy->denied=['content:data'];
            H::rejects(static function() use($transport,$f,$entry): void {
                if (in_array($entry,[EntryPoint::Stream,EntryPoint::Voice],true)) { iterator_to_array($transport->stream($f->request,$f->deployment)); }
                else { $transport->generate($f->request,$f->deployment); }
            },ContractViolation::class,ErrorCode::AccessDenied);
            H::check($f->transport->calls===1);
        }
        Proof::$observations['entry_parity']=['entries'=>array_map(static fn(EntryPoint $e): string=>$e->value,EntryPoint::cases()),'cached_grants'=>0];
    },
    'security.exact-rendered-binding'=>static function(): void {
        $f=new Fixture();$changed=$f->makeRequest(system:'untrusted override');
        H::rejects(static fn()=> $f->wrapped()->generate($changed,$f->deployment),IntegrityConflict::class);
        $schema=$f->makeRequest(tools:[new ToolDefinition('search','Allowed search',new JsonObject(['type'=>'object','secret_schema'=>'forbidden']))]);
        H::rejects(static fn()=> $f->wrapped()->generate($schema,$f->deployment),IntegrityConflict::class);
        $credential=$f->makeRequest(credential:$f->core->ref('credential:from-model'));
        H::rejects(static fn()=> $f->wrapped()->generate($credential,$f->deployment),ContractViolation::class,ErrorCode::IntegrityConflict);
        $f->policy->tools=[];
        H::rejects(static fn()=> $f->wrapped()->generate($f->request,$f->deployment),ContractViolation::class,ErrorCode::AccessDenied);
        $f->api->revision='other-renderer/v2';
        H::rejects(static fn()=> $f->wrapped()->generate($f->request,$f->deployment),ContractViolation::class,ErrorCode::StaleContext);
        H::check($f->transport->calls===0);
    },
    'security.emergency-and-unknown'=>static function(): void {
        $f=new Fixture();$f->policy->emergency=true;
        H::rejects(static fn()=> $f->wrapped()->generate($f->request,$f->deployment),ContractViolation::class,ErrorCode::DeploymentUnavailable);
        $f->policy->emergency=null;
        H::rejects(static fn()=> $f->wrapped()->generate($f->request,$f->deployment),ContractViolation::class,ErrorCode::CapabilityUnavailable);
        $f->policy->emergency=false;$f->policy->revision=null;
        H::rejects(static fn()=> $f->wrapped()->generate($f->request,$f->deployment),ContractViolation::class,ErrorCode::CapabilityUnavailable);
        $f->policy->revision=1;$f->policy->access=AccessState::Unknown;
        H::rejects(static fn()=> $f->wrapped()->generate($f->request,$f->deployment),ContractViolation::class,ErrorCode::CapabilityUnavailable);
        H::check($f->transport->calls===0);
        $f=new Fixture();$f->policy->afterSource=static function() use($f): void { $f->policy->time=$f->request->deadline; };
        H::rejects(static fn()=> $f->wrapped()->generate($f->request,$f->deployment),ContractViolation::class,ErrorCode::DeadlineExceeded);
        H::check($f->transport->calls===0);
    },
    'security.snapshot-integrity-and-scope'=>static function(): void {
        $f=new Fixture();
        H::rejects(static fn()=> new GuardSnapshot($f->plan,new CompiledContext($f->compiled->snapshot,'tampered',$f->compiled->manifestJson),$f->target,$f->core->hasher),IntegrityConflict::class);
        $m=Values::object(json_decode($f->compiled->manifestJson,true,64,JSON_THROW_ON_ERROR));$m['sections']=array_slice(Values::rows($m['sections']),1);
        unset($m['snapshot_id'],$m['immutable_content_hash']);$hash=$f->core->hasher->hash($f->core->tenant,'context-snapshot',['manifest'=>$m,'rendered'=>$f->compiled->rendered]);
        $m['snapshot_id']=$f->snapshot->ref()->value;$m['immutable_content_hash']=$hash->value;
        $forged=new CompiledContext(new ContextSnapshot($f->snapshot->ref(),1,$hash,$f->compiled->snapshot->scopeHash),$f->compiled->rendered,json_encode($m,JSON_THROW_ON_ERROR));
        H::rejects(static fn()=> new GuardSnapshot($f->plan,$forged,$f->target,$f->core->hasher),ContractViolation::class,ErrorCode::IntegrityConflict);
        $f->host->actor='other-actor';
        H::rejects(static fn()=> $f->wrapped()->generate($f->request,$f->deployment),ContractViolation::class,ErrorCode::StaleContext);
        H::check($f->transport->calls===0);
    },
]);
