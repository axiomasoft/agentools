<?php

declare(strict_types=1);

use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Routing\Fixture as F;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, UtcInstant};
use AgenToolsPlatform\Core\Models\{Capability, ContinuationRef, ModelRequest, Support};
use AgenToolsPlatform\Core\Models\Routing\{AdminState, EmergencyRule, HostDecision, QualifiedTransport, Reason};

return [
    'routing.current-check-before-io'=>static function (): void {
        $f = new F(); $e = $f->entry(); $r = $f->request(); $f->states = [$f->qualified($e)]; $router = $f->router();
        H::check($router->route($r,$f->now())->selected === $e && $router->revalidate($r,$e,$f->now()) === []);
        $transport = new QualifiedTransport($f,$router,$r,$e,$f);
        $response = $transport->generate($f->modelRequest(),$e->deployment); H::check($f->calls === 1 && count($response->parts) === 1);
        $f->host = new HostDecision(Support::Supported,Support::Unsupported,Support::Supported);
        H::rejects(static fn ()=>$transport->generate($f->modelRequest(),$e->deployment),ContractViolation::class,ErrorCode::AccessDenied);
        H::check($f->calls === 1);
        $f->host = new HostDecision(Support::Supported,Support::Supported,Support::Supported);
        $f->rules = [new EmergencyRule('incident','source',account:'account-a')];
        H::rejects(static fn ()=>$transport->generate($f->modelRequest(),$e->deployment),ContractViolation::class,ErrorCode::AccessDenied);
        H::check($f->calls === 1);
    },
    'routing.draining-existing-and-incident'=>static function (): void {
        $f = new F(); $e = $f->entry(); $r = $f->request();
        $f->states = [$f->qualified($e,null,['admin'=>AdminState::Draining])];
        $existing = new QualifiedTransport($f,$f->router(),$r,$e,$f,true);
        $existing->generate($f->modelRequest(),$e->deployment); H::check($f->calls === 1);
        $new = new QualifiedTransport($f,$f->router(),$r,$e,$f);
        H::rejects(static fn ()=>$new->generate($f->modelRequest(),$e->deployment),ContractViolation::class,ErrorCode::AccessDenied);
        $f->states = [$f->qualified($e,null,['admin'=>AdminState::IncidentBlocked])];
        H::rejects(static fn ()=>$existing->generate($f->modelRequest(),$e->deployment),ContractViolation::class,ErrorCode::AccessDenied);
        H::check($f->calls === 1);
    },
    'routing.stream-current-and-capability'=>static function (): void {
        $f = new F(); $e = $f->entry(); $r = $f->request(['capabilities'=>[Capability::Text,Capability::Streaming]]);
        $f->states = [$f->qualified($e,$r)]; $transport = new QualifiedTransport($f,$f->router(),$r,$e,$f);
        $stream = $transport->stream($f->modelRequest(),$e->deployment); H::check($f->calls === 0);
        $f->rules = [new EmergencyRule('incident','source',region:'RU')];
        H::rejects(static function () use ($stream): void { foreach ($stream as $_) {} },ContractViolation::class,ErrorCode::AccessDenied);
        H::check($f->calls === 0);
        $f->rules = []; $r = $f->request(); $f->states = [$f->qualified($e,$r)]; $transport = new QualifiedTransport($f,$f->router(),$r,$e,$f);
        H::rejects(static function () use ($transport,$f,$e): void { foreach ($transport->stream($f->modelRequest(),$e->deployment) as $_) {} },ContractViolation::class,ErrorCode::CapabilityUnavailable);
        H::check($f->calls === 0);
    },
    'V2ALL-MODEL-NEGATIVE'=>static function (): void {
        $f = new F(); $e = $f->entry(); $r = $f->request(['capabilities'=>[Capability::Text,Capability::Continuation]]); $f->states = [$f->qualified($e,$r)];
        $transport = new QualifiedTransport($f,$f->router(),$r,$e,$f); $base = $f->modelRequest();
        foreach (['provider'=>'other','account'=>'other','region'=>'EU','tenant'=>'beta','expires_at'=>$f->now()->value] as $key=>$value) {
            $binding = array_replace(['provider'=>'scripted','account'=>'account-a','region'=>'RU','tenant'=>'alpha','expires_at'=>'2026-12-01T00:00:00Z'],[$key=>$value]);
            H::rejects(static function () use ($binding,$base,$transport,$e): void {
                $continuation = new ContinuationRef(F::ref('opaque',$binding['tenant']),$binding['provider'],$binding['account'],$binding['region'],new UtcInstant($binding['expires_at']));
                $request = new ModelRequest($base->invocationId,$base->messages,$base->contextSnapshotRef,$base->contextSnapshotHash,$base->credentialRef,$base->deadline,continuation:$continuation);
                $transport->generate($request,$e->deployment);
            },ContractViolation::class,ErrorCode::AccessDenied);
        }
        H::check($f->calls === 0);
        $changed = $f->entry(['snapshot_ref'=>'new']); $f->states = [$f->qualified($changed,$r)];
        H::check($f->router()->revalidate($r,$e,$f->now()) === [Reason::Binding]);
        H::rejects(static fn ()=>$transport->generate($base,$e->deployment),ContractViolation::class,ErrorCode::AccessDenied);
        H::check($f->calls === 0);
    },
];
