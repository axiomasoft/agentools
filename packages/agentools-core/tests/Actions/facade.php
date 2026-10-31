<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Actions\{ActionAccess, ActionApplication, ActionRegistration, ActionRegistry, ActionView, CancelRequest, ReconcileRequest};
use AgenToolsPlatform\Core\Common\{ActionId, ActionReceipt, ContractViolation, ErrorCode, Ref, TenantId};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;
use AgenToolsPlatform\Tests\{Harness as H, TestHost};
use AgenToolsPlatform\Tests\Actions\{DatedLifecycle, Fixtures as F, Handler, Policy, Schema};

require_once __DIR__ . '/Fixtures.php';
require_once __DIR__ . '/DatedLifecycle.php';
$cases = [
    'facade.accepted-replay-conflict' => static function (): void {
        $d = F::definition(); $host = new TestHost(new TenantId('tenant-a')); $context = F::context($host);
        $registration = new ActionRegistration($d, new Handler()); $policy = new Policy(F::access($d));
        $schemas = new Schema(); $backend = new DatedLifecycle();
        $app = new ActionApplication(new ActionRegistry([$registration]), $policy, $host, $schemas, $backend);
        $submission = F::submission($d, $context);
        $first = $app->submit($submission)->view;
        H::check($first->receipt->wireStatus === 'created' && $first->receipt->version === 1 && $first->safeNextStep === 'status');
        H::check($first === $app->submit($submission)->view && count($backend->journal->events()) === 1);
        H::rejects(static fn () => $app->submit(F::submission($d, $context, '{"query":"changed"}')), ContractViolation::class, ErrorCode::IntegrityConflict);
        H::check($schemas->seen === [$d->inputSchema, $d->inputSchema, $d->inputSchema]);
        H::check($app->status($first->receipt->actionId, $context) === $first && $backend->mutations === 0);
    },
    'facade.guards-before-submit' => static function (): void {
        $d = F::definition(); $host = new TestHost(new TenantId('tenant-a')); $context = F::context($host);
        $policy = new Policy(F::access($d)); $backend = new DatedLifecycle();
        $app = new ActionApplication(new ActionRegistry([new ActionRegistration($d, new Handler())]), $policy, $host, new Schema(), $backend);
        H::rejects(static fn () => $app->submit(F::submission($d, $context, '{"handler":"App\\\\Attack","query":"hello"}')), ContractViolation::class, ErrorCode::InvalidInput);
        H::rejects(static fn () => F::submission($d, $context, '{"query":"first","query":"second"}'), InvalidArgumentException::class);
        $valid = F::submission($d, $context);
        $json = new \AgenToolsPlatform\Tests\JcsAdapter();
        H::rejects(static fn () => new \AgenToolsPlatform\Core\Actions\ActionSubmission($valid->request,
            '{"query":"substituted"}', new \AgenToolsPlatform\Core\Common\CanonicalHasher($json), $json), DomainException::class);
        H::rejects(static fn () => $app->submit(F::submission(F::definition('unregistered'), $context)), ContractViolation::class, ErrorCode::CapabilityUnavailable);
        $host->denied = true;
        H::rejects(static fn () => $app->submit(F::submission($d, $context)), ContractViolation::class, ErrorCode::AccessDenied);
        $host->denied = false;
        $policy->access = new ActionAccess([], [], [], [], [], []);
        H::rejects(static fn () => $app->submit(F::submission($d, $context)), ContractViolation::class, ErrorCode::AccessDenied);
        H::check($backend->submits === 0 && $backend->mutations === 0);
    },
    'facade.retry-current-restrictions' => static function (): void {
        $d = F::definition(); $host = new TestHost(new TenantId('tenant-a')); $context = F::context($host);
        $policy = new Policy(F::access($d)); $backend = new DatedLifecycle();
        $app = new ActionApplication(new ActionRegistry([new ActionRegistration($d, new Handler())]), $policy, $host, new Schema(), $backend);
        $submission = F::submission($d, $context); $saved = $app->submit($submission)->view;
        $policy->access = new ActionAccess([], [], [], [], [], []);
        H::rejects(static fn () => $app->submit($submission), ContractViolation::class);
        H::rejects(static fn () => $app->cancel($saved->receipt->actionId, new CancelRequest(new Ref($host->tenant, 'cancel'), 1, $context)), ContractViolation::class);
        H::check($backend->submits === 1 && $backend->mutations === 0);
        $policy->access = F::access($d); $host->revision = 'revoked-policy';
        H::rejects(static fn () => $app->submit($submission), ContractViolation::class, ErrorCode::StaleContext);
        H::check($backend->submits === 1);
    },
    'facade.tenant-and-result-binding' => static function (): void {
        $d = F::definition(); $host = new TestHost(new TenantId('tenant-a')); $context = F::context($host);
        $backend = new DatedLifecycle();
        $app = new ActionApplication(new ActionRegistry([new ActionRegistration($d, new Handler())]), new Policy(F::access($d)), $host, new Schema(), $backend);
        $saved = $app->submit(F::submission($d, $context))->view;
        H::rejects(static fn () => $app->status(new ActionId(new TenantId('tenant-b'), 'foreign'), $context), DomainException::class);
        H::check($backend->lookups === 0);
        $backend->view = new ActionView(new ActionReceipt(new ActionId($host->tenant, 'different'), 'created', 1), $d->ref, 'status');
        H::rejects(static fn () => $app->status($saved->receipt->actionId, $context), ContractViolation::class, ErrorCode::IntegrityConflict);
        $backend->view = new ActionView($saved->receipt, new DefinitionRef($d->ref->id, '2', $d->ref->hash), 'status');
        H::rejects(static fn () => $app->status($saved->receipt->actionId, $context), ContractViolation::class);
    },
    'action.dated-mapping-and-unknown' => static function (): void {
        $d = F::definition(); $host = new TestHost(new TenantId('tenant-a')); $context = F::context($host);
        $registration = new ActionRegistration($d, new Handler()); $backend = new DatedLifecycle();
        $view = $backend->submit(F::submission($d, $context), $registration); $id = $view->receipt->actionId;
        H::check($view->projectPhase('pending') === 'created');
        $backend->append('action_started', $id);
        $view = $backend->projection($id, $registration);
        H::check($view->projectPhase('ready', true) === 'running');
        H::rejects(static fn () => $view->projectPhase('ready'), ContractViolation::class);
        $backend->append('epoch_started', $id, [], 1);
        $backend->append('effect_requested', $id, ['effect_id' => 'owner-effect', 'idempotency_key' => 'original-owner-key'], 1);
        $view = $backend->projection($id, $registration);
        H::check($view->projectPhase('executing') === 'applying');
        $backend->append('cancellation_requested', $id);
        H::check($backend->journal->state()->status?->value === 'applying');
        $backend->append('task_binding_lost', $id);
        $before = $backend->journal->state($backend->reducer);
        H::check($before->status?->value === 'reconciliation_required' && $before->hasUnresolvedEffect());
        $backend->append('future.provider_lookup_unknown', $id, ['outcome' => 'unknown']);
        $after = $backend->journal->state($backend->reducer);
        H::check($after->status === $before->status && $after->seq === $before->seq + 1 && $after->effects == $before->effects);
        H::check($after->previousHash !== null && $after->previousHash !== $before->previousHash);
        H::check($backend->projection($id, $registration)->projectPhase('reconciling') === 'reconciliation_required');
        H::rejects(static fn () => $backend->append('action_cancelled', $id), \AgenTools\Action\LifecycleException::class);
        $backend->append('effect_confirmed', $id, ['effect_id' => 'owner-effect', 'external_ref' => 'known-owner-result'], 1);
        $backend->append('action_completed', $id);
        $completed = $backend->projection($id, $registration);
        H::check($completed->projectPhase('succeeded') === 'completed');
        H::rejects(static fn () => $backend->append('cancellation_requested', $id), \AgenTools\Action\LifecycleException::class);
        foreach (['pending', 'ready', 'executing', 'succeeded', 'unknown', 'manual_resolution_required'] as $raw) {
            H::rejects(static fn () => new ActionReceipt($id, $raw, 1), InvalidArgumentException::class);
        }
    },
    'facade.cancel-reconcile-no-effect-loop' => static function (): void {
        $d = F::definition(); $host = new TestHost(new TenantId('tenant-a')); $context = F::context($host);
        $backend = new DatedLifecycle();
        $app = new ActionApplication(new ActionRegistry([new ActionRegistration($d, new Handler())]), new Policy(F::access($d)), $host, new Schema(), $backend);
        $saved = $app->submit(F::submission($d, $context))->view; $id = $saved->receipt->actionId;
        $cancel = new CancelRequest(new Ref($host->tenant, 'cancel'), 1, $context);
        $reconcile = new ReconcileRequest(new Ref($host->tenant, 'reconcile'), 1, $context);
        H::check(!(new ReflectionClass($reconcile))->hasProperty('ownerOperationKey'));
        H::check($app->cancel($id, $cancel)->view === $saved && $app->reconcile($id, $reconcile)->view === $saved);
        H::check($backend->mutations === 2 && $backend->submits === 1);
        $backend->view = new ActionView(new ActionReceipt($id, 'completed', 3), $d->ref, 'none');
        $backend->forcedMutation = new ActionView(new ActionReceipt($id, 'cancelled', 4), $d->ref, 'none');
        H::rejects(static fn () => $app->cancel($id, $cancel), ContractViolation::class, ErrorCode::IntegrityConflict);
        $backend->view = $saved;
        $two = F::definition('2');
        $backend->forcedMutation = new ActionView($saved->receipt, $two->ref, 'status');
        $keys = [ActionAccess::key($d->ref), ActionAccess::key($two->ref)];
        $both = new ActionAccess($keys, $keys, $keys, $keys, $keys, ['records.read']);
        $withTwo = new ActionApplication(new ActionRegistry([new ActionRegistration($d, new Handler()),
            new ActionRegistration($two, new Handler())]), new Policy($both), $host, new Schema(), $backend);
        H::rejects(static fn () => $withTwo->reconcile($id, $reconcile), ContractViolation::class, ErrorCode::IntegrityConflict);

    },
];
if (realpath($argv[0] ?? '') === __FILE__) { H::run($cases, 'unit', 'unit'); }
return $cases;
