<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Tests;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Filament\{Access, Diagnostics, Inspector, InspectorServiceProvider, Target};
use AgenToolsPlatform\Laravel\Tests\Composition\Fixture as RuntimeFixture;

final class HostAccess implements Access
{
    public bool $allowed = true;
    public bool $sensitive = false;
    public bool $auditFails = false;
    public bool $resetFails = false;
    public bool $readFails = false;
    public ?int $revokeAt = null;
    public int $authorizations = 0;
    public int $resets = 0;
    /** @var list<array<string,string>> */
    public array $events = [];
    public function __construct(public ExecutionContext $current) {}
    public function context(): ExecutionContext { return $this->current; }
    public function authorize(ExecutionContext $context, Target $target, string $permission): void
    {
        ++$this->authorizations;
        if ($this->readFails) { throw new \RuntimeException('alpha secret nested key/tool payload'); }
        if (!$this->allowed || ($this->revokeAt !== null && $this->authorizations >= $this->revokeAt)
            || ($permission === 'diagnostics' && !$this->sensitive)) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $this->current->identity->tenant->assertSame($context->identity->tenant);
        if ($this->current->identity->actorRef->value !== $context->identity->actorRef->value) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
    public function record(ExecutionContext $context, Target $target, string $permission, string $code): void
    {
        if ($this->auditFails) { throw new \RuntimeException('alpha secret audit payload'); }
        $this->events[] = ['tenant' => $context->identity->tenant->value, 'actor' => $context->identity->actorRef->value,
            'session' => $context->session->value, 'target' => $target->id->value, 'permission' => $permission, 'code' => $code];
    }
    public function reset(): void
    {
        ++$this->resets;
        if ($this->resetFails) { throw new \RuntimeException('alpha secret reset'); }
    }
}

final class DiagnosticStore implements Diagnostics
{
    public int $reads = 0;
    public int $resets = 0;
    public ?\Closure $duringRead = null;
    public function read(ExecutionContext $context, Target $target, \DateTimeImmutable $expiresAt): string
    {
        ++$this->reads;
        if ($this->duringRead !== null) { ($this->duringRead)(); }
        return 'alpha sensitive diagnostic';
    }
    public function reset(): void { ++$this->resets; }
}

final class Fixture
{
    public RuntimeFixture $runtime;
    public HostAccess $access;
    public DiagnosticStore $diagnostics;
    public function __construct()
    {
        $this->runtime = new RuntimeFixture();
        $this->runtime->app->make(\AgenToolsPlatform\Laravel\Managed\HostBindings::class);
        $this->access = new HostAccess($this->runtime->current()->context);
        $this->diagnostics = new DiagnosticStore();
        $provider = new InspectorServiceProvider($this->runtime->app);
        $provider->register(); $provider->boot($this->runtime->events);
        $this->runtime->app->scoped(Access::class, fn (): Access => $this->access);
        $this->runtime->app->scoped(Diagnostics::class, fn (): Diagnostics => $this->diagnostics);
    }
    public function inspector(): Inspector { return $this->runtime->app->make(Inspector::class); }
    public function close(): void { $this->runtime->close(); }
}
