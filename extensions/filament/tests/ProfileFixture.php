<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Tests;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, InstanceId, Ref};
use AgenToolsPlatform\Core\Profiles\{BundleManifest, BundleRef, BundleValidator, ManifestCodec};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Filament\Profiles\{Access, Admin, Bindings, Operation, Request, Result};
use AgenToolsPlatform\Postgres\Profiles\{BundleRepository, Schema, SessionRepository};
use AgenToolsPlatform\Postgres\Tests\Profiles\TestAuthority;
use AgenToolsPlatform\Tests\Profiles\Merge\Fixture as MergeFixture;

final class ProfileAccess implements Access
{
    public bool $allowed = true;
    public bool $auditFails = false;
    public bool $failCompletedAudit = false;
    public bool $resetFails = false;
    public int $resets = 0;
    public int $calls = 0;
    public ?int $revokeAt = null;
    public ?string $deniedInstance = null;
    public ?\Closure $duringAudit = null;
    /** @var list<array<string,mixed>> */
    public array $events = [];
    public function __construct(public ExecutionContext $current) {}
    public function context(): ExecutionContext { return $this->current; }
    public function authorize(ExecutionContext $context, BundleRef $target, Operation $operation, ?InstanceId $instance): void
    {
        ++$this->calls;
        if (!$this->allowed || ($instance !== null && $this->deniedInstance === $instance->value)
            || ($this->revokeAt !== null && $this->calls >= $this->revokeAt)) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
    public function record(ExecutionContext $context, BundleRef $target, Operation $operation, string $code, ?Ref $reason, ?Ref $command, ?InstanceId $instance): void
    {
        if ($this->duringAudit !== null) { ($this->duringAudit)(); }
        if ($this->auditFails || ($this->failCompletedAudit && $code !== 'requested')) { throw new \RuntimeException('sensitive prompt in audit exception'); }
        $this->events[] = ['tenant'=>$context->identity->tenant->value, 'actor'=>$context->identity->actorRef->value,
            'session'=>$context->session->value, 'bundle'=>$target->toArray(), 'operation'=>$operation->value,
            'code'=>$code, 'reason'=>$reason?->value, 'command'=>$command?->value, 'instance'=>$instance?->value];
    }
    public function reset(): void { ++$this->resets; if ($this->resetFails) { throw new \RuntimeException('sensitive reset'); } }
}

final class ProfileFixture
{
    public Fixture $base;
    public MergeFixture $merge;
    public TestAuthority $authority;
    public ProfileAccess $access;
    public BundleRepository $bundles;
    public SessionRepository $sessions;
    public function __construct()
    {
        $this->base = new Fixture();
        $j = $this->base->runtime->current();
        Schema::install($this->base->runtime->database->pdo, $this->base->runtime->schema);
        $this->merge = new MergeFixture($j->tenant->value);
        $this->authority = new TestAuthority($this->base->runtime->database->pdo);
        $this->access = new ProfileAccess($j->context);
        $this->bundles = new BundleRepository($j->environment,
            new BundleValidator($this->merge->catalog, $this->merge->compiler), $this->authority, $this->merge->json);
        $this->sessions = new SessionRepository($j->environment, $this->bundles, $this->authority);
        $this->base->runtime->app->scoped(Bindings::class, fn (): Bindings => new Bindings($this->bundles, $this->sessions, $this->merge->compiler, $this->access));
    }
    public function admin(): Admin { return $this->base->runtime->app->make(Admin::class); }
    /** @phpstan-impure Executes current guards and reads/writes the canonical publisher. */
    public function call(Operation $operation, BundleManifest $m, int $expected = 0, string $command = 'command', string $instance = 'assistant'): Result
    {
        return $this->admin()->execute(new Request($operation, $m->bundle->ref->value, $m->bundle->version,
            $m->bundle->hash->value, $expected, (new ManifestCodec($this->merge->json))->encode($m), $instance, $command, 'reason:change'));
    }
    public function publish(BundleManifest $m): void
    {
        foreach ([[Operation::Draft,0],[Operation::Validate,1],[Operation::Publish,2]] as [$op,$revision]) {
            $result = $this->call($op, $m, $revision);
            if ($result->code !== 'ok') { throw new \RuntimeException('Fixture publication failed: '.$result->code); }
        }
    }
    public function close(): void { $this->base->close(); }
}
