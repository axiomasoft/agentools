<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref,SessionId,TenantId,UtcInstant};
use AgenToolsPlatform\Core\Context\{CompiledContext,DiagnosticRef,Diagnostics};
use AgenToolsPlatform\Core\Security\{Correlation,ExecutionContext,HostAuthorization,HostIdentity,LogCode,LogProjection,ResettableResource,ResourceKind,TraceLinks,WorkerJob,WorkerScope};
use AgenToolsPlatform\Tests\Security\{Fixture,Proof};
use AgenToolsPlatform\Tests\Harness as H;

final class ScopedResource implements ResettableResource
{
    public ?string $value=null;public int $resets=0;public bool $fail=false;
    public function __construct(private ResourceKind $type) {}
    public function kind(): ResourceKind { return $this->type; }
    public function reset(): void { ++$this->resets;if ($this->fail) { throw new RuntimeException('sensitive stale resource'); }$this->value=null; }
}
/** @return list<ScopedResource> */
function resources(): array { return array_map(static fn(ResourceKind $k): ScopedResource=>new ScopedResource($k),ResourceKind::cases()); }
function workerHost(): HostAuthorization
{
    return new class implements HostAuthorization {
        public function authorize(SessionId $session,string $purpose): HostIdentity
        { $t=$session->tenant;return new HostIdentity($t,new Ref($t,'actor:'.$t->value),null,new Ref($t,'auth:'.$t->value),'policy-1'); }
    };
}

Proof::run([
    'security.worker-isolation'=>static function(): void {
        $resources=resources();$scope=new WorkerScope(workerHost(),$resources);
        $job=new class($resources) implements WorkerJob {
            public int $calls=0;
            /** @param list<ScopedResource> $resources */
            public function __construct(private array $resources) {}
            public function run(ExecutionContext $context): Ref
            {
                foreach ($this->resources as $resource) { H::check($resource->value===null);$resource->value=$context->identity->tenant->value.':private:'.$resource->kind()->value; }
                ++$this->calls;if ($context->identity->tenant->value==='alpha') { throw new RuntimeException('alpha secret in nested failure'); }
                return new Ref($context->identity->tenant,'job:result');
            }
        };
        H::rejects(static fn()=> $scope->execute(new SessionId(new TenantId('alpha'),'session'),'support.read',new Correlation('trace-a','req-a'),$job),RuntimeException::class);
        $result=$scope->execute(new SessionId(new TenantId('beta'),'session'),'support.read',new Correlation('trace-b','req-b'),$job);
        H::check($result->tenant->value==='beta' && $job->calls===2);
        foreach ($resources as $resource) { H::check($resource->value===null && $resource->resets===4); }
        Proof::$observations['worker_isolation']=['jobs'=>2,'resource_kinds'=>count($resources),'alpha_refs_in_beta'=>0,'finally_reset'=>true];
    },
    'security.worker-reset-fault'=>static function(): void {
        $resources=resources();$resources[0]->fail=true;$resources[0]->value='alpha:secret';$scope=new WorkerScope(workerHost(),$resources);
        $job=new class implements WorkerJob { public int $calls=0;public function run(ExecutionContext $context): Ref { ++$this->calls;return new Ref($context->identity->tenant,'result'); } };
        $run=static fn()=> $scope->execute(new SessionId(new TenantId('beta'),'session'),'support.read',new Correlation('trace','request'),$job);
        H::rejects($run,RuntimeException::class);H::rejects($run,RuntimeException::class);H::check($job->calls===0);
        foreach ($resources as $resource) { H::check($resource->resets===1); }
        H::rejects(static fn()=> new WorkerScope(workerHost(),array_slice($resources,1)),InvalidArgumentException::class);
    },
    'security.redacted-logs-diagnostics'=>static function(): void {
        $f=new Fixture();$secret='nested-child-book-tool-secret';$error=new RuntimeException($secret,0,new RuntimeException('previous '.$secret));
        $record=LogProjection::record($f->core->context,LogCode::Failure,new TraceLinks(snapshot:$f->snapshot->ref(),invocation:$f->core->ref('invocation:guard'),hash:$f->snapshot->hash()),
            ['messages'=>[['content'=>$secret]],'http'=>['headers'=>['Authorization'=>$secret]],'exception'=>['message'=>$secret]],$error);
        $json=json_encode($record,JSON_THROW_ON_ERROR);H::check(!str_contains($json,$secret) && !str_contains($json,'Authorization') && $record['error_code']==='internal_error');
        H::check(str_contains($json,'trace') && str_contains($json,'invocation:guard') && $record['redacted']===true);
        H::rejects(static fn()=> LogProjection::record($f->core->context,LogCode::Allowed,new TraceLinks(run:new Ref(new TenantId('foreign'),'run'))),InvalidArgumentException::class);
        $sink=new class implements Diagnostics {
            public bool $allowed=false;public int $calls=0;
            public function capture(ExecutionContext $context,CompiledContext $compiled,UtcInstant $expiresAt): DiagnosticRef {
                if (!$this->allowed) { throw new ContractViolation(ErrorCode::AccessDenied); }++$this->calls;$t=$context->identity->tenant;
                return new DiagnosticRef(new Ref($t,'encrypted:diagnostic'),$compiled->snapshot->contentHash,$expiresAt,new Ref($t,'diagnostic:access'),new Ref($t,'diagnostic:audit'),true);
            }
        };
        $expiry=new UtcInstant('2026-11-01T13:00:00Z');
        H::rejects(static fn()=> $f->core->compiler->captureDiagnostics($f->core->context,$f->compiled,$sink,$f->core->now,$expiry),ContractViolation::class,ErrorCode::AccessDenied);
        H::check($sink->calls===0);$sink->allowed=true;
        H::rejects(static fn()=> $f->core->compiler->captureDiagnostics($f->core->context,$f->compiled,$sink,$f->core->now,$f->core->now),InvalidArgumentException::class);
        $ref=$f->core->compiler->captureDiagnostics($f->core->context,$f->compiled,$sink,$f->core->now,$expiry);
        H::check($ref->encrypted && $ref->expiresAt->value===$expiry->value && $sink->calls===1 && !str_contains($f->compiled->manifestJson,'ignore policy'));
        Proof::$observations['redaction']=['nested_values_redacted'=>true,'exception_chain_redacted'=>true,'diagnostic_calls'=>1,'encrypted'=>true,'ttl'=>true,'audit'=>true];
    },
]);
