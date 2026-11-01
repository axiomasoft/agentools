<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Profiles;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, InstanceId, IntegrityConflict, PayloadHash, Ref};
use AgenToolsPlatform\Core\Profiles\{BundleRef, BundleState, ManifestCodec};
use AgenToolsPlatform\Core\Profiles\Composition\ProfileConflict;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Filament\InspectionFailure;
use AgenToolsPlatform\Laravel\Managed\{HostBindings, LifecycleHealth};
use AgenToolsPlatform\Postgres\Profiles\StoredBundle;

/** Authorization/audit/projection adapter only. All publication and pin writes belong to P7.1. */
final class Admin
{
    private bool $retired = false;

    public function __construct(private readonly Bindings $ports, private readonly HostBindings $runtime,
        private readonly LifecycleHealth $health) {}

    public function execute(Request $request): Result
    {
        $context = null; $target = null; $reason = null; $command = null; $instance = null; $effect = false;
        try {
            $this->active();
            $context = $this->ports->access->context();
            $tenant = $context->identity->tenant;
            if (strlen($request->manifest) > 1048576) { throw new \InvalidArgumentException(); }
            $manifest = $request->operation === Operation::Draft
                ? (new ManifestCodec($this->runtime->json))->decode($request->manifest, $tenant) : null;
            $target = $manifest !== null ? $manifest->bundle : new BundleRef(new Ref($tenant, $request->id), $request->version, new PayloadHash($request->hash));
            if ($request->operation === Operation::Assign) { $instance = new InstanceId($tenant, $request->instance); }
            $this->authorize($context, $target, $request->operation, $instance);
            if ($request->operation !== Operation::Inspect) { $reason = new Ref($tenant, $request->reason); }
            if (in_array($request->operation, [Operation::Assign, Operation::Migrate], true)) { $command = new Ref($tenant, $request->command); }
            if ($request->expected < 0 || $request->expected >= 9007199254740991) { throw new \InvalidArgumentException(); }
            $this->audit($context, $target, $request->operation, 'requested', $reason, $command, $instance);
            $this->authorize($context, $target, $request->operation, $instance);
            $result = match ($request->operation) {
                Operation::Draft => $this->ports->bundles->draft($context, $manifest ?? throw new \InvalidArgumentException(), $request->expected),
                Operation::Inspect => $this->ports->bundles->inspect($context, $target),
                Operation::Validate => $this->ports->bundles->validate($context, $target, $request->expected),
                Operation::Publish => $this->ports->bundles->publish($context, $target, $request->expected),
                Operation::Deprecate => $this->ports->bundles->advance($context, $target, $request->expected, BundleState::Deprecated),
                Operation::Retire => $this->ports->bundles->advance($context, $target, $request->expected, BundleState::Retired),
                Operation::Assign => $this->ports->sessions->assign($context, $command ?? throw new \InvalidArgumentException(),
                    $instance ?? throw new \InvalidArgumentException(), $this->ports->bundles->inspect($context, $target)->manifest->definition,
                    $target, $request->expected),
                // Migration is a separate explicit operation, for the host-selected session only.
                Operation::Migrate => $this->ports->sessions->migrate($context, $command ?? throw new \InvalidArgumentException(),
                    $request->expected, $target, ($reason ?? throw new \InvalidArgumentException())->value),
            };
            $effect = $request->operation !== Operation::Inspect;
            $this->authorize($context, $target, $request->operation, $instance);
            if ($result instanceof StoredBundle) {
                $conflict = null;
                if (in_array('COMPILATION_FAILED', $result->issues, true)) {
                    $resolved = $this->ports->compiler->resolve($result->manifest);
                    if ($resolved instanceof ProfileConflict) { $conflict = $resolved; }
                }
                $m = $result->manifest;
                $projection = new Result($result->issues === [] ? 'ok' : 'validation_failed', [
                    'bundle' => $m->bundle->toArray(), 'state' => $result->state->value, 'revision' => $result->revision,
                    'compiler' => $m->compiler->toArray(), 'provenance' => $m->provenance->toArray(),
                    'dependencies' => array_map(static fn ($d): array => $d->toArray(), $m->dependencies),
                ], $result->issues, $conflict);
            } else {
                $projection = new Result('ok', ['bundle' => $target->toArray(),
                    'revision' => is_int($result) ? $result : $result->revision,
                    'applies_to' => is_int($result) ? 'new_sessions' : 'explicit_session_migration']);
            }
            $this->audit($context, $target, $request->operation, $projection->code, $reason, $command, $instance);
            $this->authorize($context, $target, $request->operation, $instance);
            return $projection;
        } catch (\AgenToolsPlatform\Laravel\Managed\LifecycleFailure $error) {
            throw $error;
        } catch (\Throwable $error) {
            $code = $effect ? 'outcome_unknown' : self::code($error);
            if ($context !== null && $target !== null) {
                try { $this->audit($context, $target, $request->operation, $code, $reason, $command, $instance); }
                catch (\Throwable) { $code = $effect ? 'outcome_unknown' : 'audit_failed'; }
            }
            return new Result($code);
        }
    }

    private function authorize(ExecutionContext $context, BundleRef $target, Operation $operation, ?InstanceId $instance): void
    {
        $this->active();
        $fresh = $this->ports->access->context();
        $context->scopeHash($this->runtime->hasher)->assertSame($fresh->scopeHash($this->runtime->hasher));
        $context->session->tenant->assertSame($target->ref->tenant);
        $context->assertCurrent($this->runtime->authorization);
        $this->ports->access->authorize($context, $target, $operation, $instance);
    }

    private function audit(ExecutionContext $context, BundleRef $target, Operation $operation, string $code, ?Ref $reason, ?Ref $command, ?InstanceId $instance): void
    {
        try { $this->ports->access->record($context, $target, $operation, $code, $reason, $command, $instance); }
        catch (\Throwable) { throw new InspectionFailure('audit_failed'); }
    }

    private static function code(\Throwable $error): string
    {
        if ($error instanceof InspectionFailure) { return in_array($error->reason, ['scope_retired', 'audit_failed'], true) ? $error->reason : 'operation_failed'; }
        if ($error instanceof IntegrityConflict) { return 'integrity_conflict'; }
        if ($error instanceof ContractViolation) {
            return match ($error->errorCode) {
                ErrorCode::AccessDenied, ErrorCode::Unauthenticated, ErrorCode::StaleContext => 'access_denied',
                ErrorCode::OptimisticConflict => 'optimistic_conflict',
                ErrorCode::PolicyConflict => 'policy_conflict',
                ErrorCode::CapabilityUnavailable => 'unavailable',
                default => 'operation_failed',
            };
        }
        return $error instanceof \InvalidArgumentException || $error instanceof \JsonException || $error instanceof \ValueError
            ? 'invalid_input' : 'outcome_unknown';
    }

    private function active(): void
    {
        $this->health->assertUsable();
        if ($this->retired) { throw new InspectionFailure('scope_retired'); }
    }
    public function retire(): void { $this->retired = true; }
}
