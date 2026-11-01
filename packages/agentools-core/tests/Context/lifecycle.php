<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, UtcInstant, Ref, TenantId};
use AgenToolsPlatform\Core\Context\{ContextBlock, ContextTarget, DiagnosticRef, Diagnostics, CompiledContext, Provenance, SourceKind, SnapshotPins, TokenCounter, Renderer};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Tests\Context\Fixture;
use AgenToolsPlatform\Tests\Harness as H;

H::run([
    'V2ALL-CONTEXT-POSITIVE'=>static function(): void {
        $f=new Fixture(); $pins=new SnapshotPins([['ref'=>$f->ref('skill:v1'),'version'=>'v1','hash'=>$f->bundle->hash]],$f->ref('route:v1'),['temperature'=>0]);
        $p=new Provenance(SourceKind::History,[$f->ref('message:7'),$f->ref('message:8')],$f->ref('turn:8'),'summary:v2',$f->now);
        $plan=$f->plan([$f->block('recent','recent','Exact history',true,provenance:$p)],$pins);
        $target=$f->compiler->selectDeployment($plan,[new ContextTarget($f->ref('illegal'),8000,2000,500,2000),$f->target()],$f->now);
        $first=$f->compiler->finalize($plan,$target,$f,$f->now,$f); $second=$f->compiler->finalize($plan,$target,$f,$f->now,$f);
        H::check($first->snapshot->toArray() === $second->snapshot->toArray() && $first->manifestJson === $second->manifestJson);
        $m=Fixture::object(json_decode($first->manifestJson,true,64,JSON_THROW_ON_ERROR));
        H::check(Fixture::rows($m['source_message_ids'])[0]['id'] === 'message:7' && Fixture::rows(Fixture::object($m['pins'])['dependencies'])[0]['version'] === 'v1');
        H::check(Fixture::object(Fixture::rows($m['sections'])[1]['provenance'])['summary_version'] === 'summary:v2' && Fixture::object(Fixture::rows($m['sections'])[1]['provenance'])['fresh_at'] === $f->now->value);
        unset($m['snapshot_id'],$m['immutable_content_hash']);
        H::check($f->hasher->hash($f->tenant,'context-snapshot',['manifest'=>$m,'rendered'=>$first->rendered])->value === $first->snapshot->contentHash->value);
        H::rejects(static function() use($first): void { (new ReflectionProperty($first->snapshot,'revision'))->setValue($first->snapshot,2); },Error::class);
    },
    'V2ALL-CONTEXT-NEGATIVE'=>static function(): void {
        $f=new Fixture(); $foreign=new Fixture(); $other=new TenantId('other');
        $block=new ContextBlock(new Ref($other,'foreign-secret-id'),'evidence',new Ref($other,'foreign-source'),'v1',new Ref($other,'owner'),
            'untrusted','private','support.read',new Ref($other,'content'),'foreign secret',new UtcInstant('2030-01-01T00:00:00Z'),false,1,$f->hasher);
        $f->denied=['revoked'];
        $plan=$f->plan([$block,$f->block('revoked','evidence','revoked'),$f->block('expired','summary','expired',expiry:$f->now),
            $f->block('forbidden-tool','tools','full tool schema',provenance:new Provenance(toolId:'delete'))]);
        $result=$f->compiler->finalize($plan,$f->target(),$f,$f->now,$f);
        H::check(count($plan->blocks) === 2 && count($plan->exclusions) === 4);
        H::check(!str_contains($result->manifestJson,'foreign-secret-id') && !str_contains($result->rendered,'full tool schema'));
        H::rejects(static fn()=> (new ReflectionMethod($f->compiler,'buildPlan'))->invoke($f->compiler,$f->context,$f->turn,$f->run,$f->bundle,[new stdClass()],$f->now),InvalidArgumentException::class);
        H::rejects(static fn()=> new DiagnosticRef($f->ref('diag'),$result->snapshot->contentHash,new UtcInstant('2026-11-02T00:00:00Z'),$f->ref('access'),$f->ref('audit'),false),InvalidArgumentException::class);
    },
    'V2ALL-CONTEXT-FAULT'=>static function(): void {
        $f=new Fixture(); $plan=$f->plan([$f->block('expires','recent','old',expiry:new UtcInstant('2026-11-01T12:00:00.123457Z'))]);
        $result=$f->compiler->finalize($plan,$f->target(),$f,new UtcInstant('2026-11-01T12:00:00.123457Z'),$f);
        H::check(str_contains($result->manifestJson,'"reason":"expired"') && !str_contains($result->rendered,'old'));
        $f->denied=['input']; H::rejects(static fn()=> $f->compiler->finalize($plan,$f->target(),$f,$f->now,$f),ContractViolation::class,ErrorCode::AccessDenied);
        $f->denied=[]; $f->revokeOnCount=true;
        H::rejects(static fn()=> $f->compiler->finalize($plan,$f->target(),$f,$f->now,$f),ContractViolation::class,ErrorCode::StaleContext);
    },
    'V2ALL-TOKEN-POSITIVE'=>static function(): void {
        $f=new Fixture(); $blocks=[$f->block('a','summary','duplicate'),$f->block('b','summary','duplicate'),$f->block('state','working_state','Exact state',true)];
        $one=$f->compiler->finalize($f->plan($blocks),$f->target(),$f,$f->now,$f);
        $two=$f->compiler->finalize($f->plan(array_reverse($blocks)),$f->target(),$f,$f->now,$f);
        H::check($one->manifestJson === $two->manifestJson && $one->rendered === $two->rendered);
        H::check(str_contains($one->rendered,'Exact state') && str_contains($one->manifestJson,'"reason":"duplicate"'));
        // Text and decoded voice delta consume precisely the same pinned plan and adapters.
        H::check($one->snapshot->contentHash->value === $two->snapshot->contentHash->value);
    },
    'V2ALL-TOKEN-NEGATIVE'=>static function(): void {
        $f=new Fixture();
        H::rejects(static fn()=> $f->plan([$f->block('bad','tool_result','Forbidden schema',true,provenance:new Provenance(toolId:'delete'))]),ContractViolation::class,ErrorCode::AccessDenied);
        H::rejects(static fn()=> $f->block('optional-current','input','Ask'),InvalidArgumentException::class);
        H::rejects(static fn()=> new ContextTarget($f->deployment(),8000,100,0,101),InvalidArgumentException::class);
        $f->deploymentAllowed=false;
        H::rejects(static fn()=> $f->compiler->selectDeployment($f->plan(),[$f->target()],$f->now),ContractViolation::class,ErrorCode::CapabilityUnavailable);
        H::rejects(static fn()=> $f->compiler->finalize($f->plan(),$f->target(),$f,$f->now,$f),ContractViolation::class,ErrorCode::AccessDenied);
    },
    'V2ALL-TOKEN-FAULT'=>static function(): void {
        $f=new Fixture(); $f->changeConfidence=true;
        H::rejects(static fn()=> $f->compiler->finalize($f->plan(),$f->target(),$f,$f->now,$f),ContractViolation::class,ErrorCode::CapabilityUnavailable);
        $f=new Fixture(); $f->framing=str_repeat('API overhead',1000);
        H::rejects(static fn()=> $f->compiler->finalize($f->plan(),$f->target(),$f,$f->now,$f),\AgenToolsPlatform\Core\Context\BudgetOverflow::class);
        $other=new class($f) implements TokenCounter {
            public function __construct(private Fixture $f) {}
            public function deployment(): Ref { return $this->f->ref('other-model'); }
            public function version(): string { return 'other-model/v1'; }
            public function confidence(): string { return 'exact'; }
            public function count(string $rendered): int { return 1; }
        };
        H::rejects(static fn()=> $f->compiler->finalize($f->plan(),$f->target(),$other,$f->now,$f),ContractViolation::class);
    },
    'context.diagnostics-authorized-ttl'=>static function(): void {
        $f=new Fixture(); $compiled=$f->compiler->finalize($f->plan(),$f->target(),$f,$f->now,$f);
        $sink=new class implements Diagnostics {
            public int $calls=0;
            public function capture(ExecutionContext $context,CompiledContext $compiled,UtcInstant $expiresAt): DiagnosticRef {
                ++$this->calls; $t=$context->identity->tenant;
                return new DiagnosticRef(new Ref($t,'encrypted:diagnostic'),$compiled->snapshot->contentHash,$expiresAt,new Ref($t,'authorized'),new Ref($t,'audit'),true);
            }
        };
        H::check($sink->calls === 0 && !str_contains($compiled->manifestJson,'Current question'));
        H::rejects(static fn()=> $f->compiler->captureDiagnostics($f->context,$compiled,$sink,$f->now,$f->now),InvalidArgumentException::class);
        $receipt=$f->compiler->captureDiagnostics($f->context,$compiled,$sink,$f->now,new UtcInstant('2026-11-01T13:00:00Z'));
        H::check($sink->calls === 1 && $receipt->encrypted && !str_contains($compiled->manifestJson,'encrypted:diagnostic'));
    },
]);
