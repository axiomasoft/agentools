<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Laravel\Managed\LifecycleHealth;

final class Inspector
{
    private bool $retired = false;
    private readonly HeadlessReader $reader;

    public function __construct(private readonly Bindings $bindings, private readonly LifecycleHealth $health)
    {
        $this->reader = new HeadlessReader($bindings->runtime);
    }

    public function inspect(string $kind, string $id): Inspection
    {
        $context = null;
        $target = null;
        try {
            $this->active();
            $context = $this->bindings->access->context();
            $target = new Target($kind, $context->identity->tenant, $id);
            $this->authorize($context, $target, 'inspect');
            $result = $this->reader->read($context, $target);
            $this->authorize($context, $target, 'inspect');
        } catch (\AgenToolsPlatform\Laravel\Managed\LifecycleFailure $error) {
            throw $error;
        } catch (\Throwable $error) {
            $result = new Inspection(self::code($error));
        }
        $this->audit($context, $target, 'inspect', $result->code);
        if ($result->code === 'ok') {
            try { $this->authorize($context ?? throw new InspectionFailure(), $target ?? throw new InspectionFailure(), 'inspect'); }
            catch (\Throwable $error) {
                $failure = new Inspection(self::code($error));
                $this->audit($context, $target, 'inspect', $failure->code);
                return $failure;
            }
        }
        return $result;
    }

    /** Explicit host-only API. No route, Livewire method, persistent payload or reusable grant. */
    public function diagnostic(string $kind, string $id, int $ttlSeconds): DiagnosticResult
    {
        $context = null;
        $target = null;
        try {
            $this->active();
            $context = $this->bindings->access->context();
            $target = new Target($kind, $context->identity->tenant, $id);
            $this->authorize($context, $target, 'diagnostics');
            if ($ttlSeconds < 1 || $ttlSeconds > 300 || $this->bindings->diagnostics === null) {
                throw new InspectionFailure('diagnostics_unavailable');
            }
            // Read existence/session/definition/current policy through the same headless services.
            if ($this->reader->read($context, $target)->code !== 'ok') { throw new InspectionFailure('not_found'); }
            $expires = $this->bindings->runtime->clock->now()->modify('+'.$ttlSeconds.' seconds');
            $this->audit($context, $target, 'diagnostics', 'requested');
            $this->authorize($context, $target, 'diagnostics');
            $payload = $this->bindings->diagnostics->read($context, $target, $expires);
            $this->authorize($context, $target, 'diagnostics');
            $this->audit($context, $target, 'diagnostics', 'authorized');
            $this->authorize($context, $target, 'diagnostics');
            if ($this->bindings->runtime->clock->now() >= $expires) { throw new InspectionFailure('diagnostic_expired'); }
            return new DiagnosticResult($payload, $expires);
        } catch (\AgenToolsPlatform\Laravel\Managed\LifecycleFailure $error) {
            throw $error;
        } catch (\Throwable $error) {
            $code = self::code($error);
            $this->audit($context, $target, 'diagnostics', $code);
            throw new InspectionFailure($code);
        }
    }

    private function active(): void
    {
        $this->health->assertUsable();
        if ($this->retired) { throw new InspectionFailure('scope_retired'); }
    }

    private function authorize(ExecutionContext $context, Target $target, string $permission): void
    {
        $this->active();
        $context->identity->tenant->assertSame($target->id->tenant);
        $context->assertCurrent($this->bindings->runtime->authorization);
        $this->bindings->access->authorize($context, $target, $permission);
    }

    private function audit(?ExecutionContext $context, ?Target $target, string $permission, string $code): void
    {
        if ($context === null || $target === null) { return; } // host owns authentication failure audit
        try { $this->bindings->access->record($context, $target, $permission, $code); }
        catch (\Throwable) { throw new InspectionFailure('audit_failed'); }
    }

    private static function code(\Throwable $error): string
    {
        if ($error instanceof InspectionFailure) {
            return in_array($error->reason, ['read_failed', 'access_denied', 'audit_failed', 'not_found',
                'diagnostics_unavailable', 'diagnostic_expired', 'scope_retired'], true) ? $error->reason : 'read_failed';
        }
        if ($error instanceof ContractViolation) {
            return in_array($error->errorCode, [ErrorCode::AccessDenied, ErrorCode::Unauthenticated, ErrorCode::StaleContext], true)
                ? 'access_denied' : 'read_failed';
        }
        return $error instanceof \InvalidArgumentException ? 'invalid_target' : 'read_failed';
    }

    public function retire(): void
    {
        $this->retired = true;
    }
}
