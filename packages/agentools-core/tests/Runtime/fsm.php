<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, Ref, RunCheckpoint, RunId, RunStatus, TenantId};
use AgenToolsPlatform\Core\Runtime\{RunCommand, RunState};
use AgenToolsPlatform\Tests\{Harness as H, JcsAdapter};

require_once dirname(__DIR__) . '/Common/bootstrap.php';
$tenant = new TenantId('tenant-a');
$hasher = new CanonicalHasher(new JcsAdapter());
$run = new RunId($tenant, 'run-1');
$command = static fn (RunStatus $next, int $version = 1, int $epoch = 1, string $key = 'command-1', ?Ref $policy = null): RunCommand =>
    new RunCommand($run, new Ref($tenant, $key), new Ref($tenant, 'step-1'), $next, $version, $epoch, $policy, $hasher);
$cases = [
    'run.all-transition-pairs' => static function () use ($run, $command, $tenant): void {
        $raw = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/fixtures/platform/v1/fsm/run-v1.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($raw) || !is_array($raw['transitions'] ?? null)) {
            throw new RuntimeException('Missing transition fixture');
        }
        H::check(count($raw['transitions']) === count(RunStatus::cases()));
        $pairs = 0;
        foreach (RunStatus::cases() as $from) {
            $allowed = $raw['transitions'][$from->value] ?? null;
            H::check(is_array($allowed));
            foreach (RunStatus::cases() as $to) {
                $state = new RunState(new RunCheckpoint($run, $from, 1, 1));
                $cmd = $command($to, policy: new Ref($tenant, 'policy-decision'));
                if (is_array($allowed) && in_array($to->value, $allowed, true)) {
                    $next = $state->apply($cmd);
                    H::check($next->checkpoint->status === $to && $next->checkpoint->version === 2);
                    H::check($state->checkpoint->status === $from && $state->commands === []);
                    H::check($next->outcome($cmd)->command->stepRef->value === 'step-1');
                } else {
                    H::rejects(static fn () => $state->apply($cmd), ContractViolation::class, ErrorCode::PolicyConflict);
                }
                ++$pairs;
            }
        }
        H::check($pairs === 100);
    },
    'RUN-007' => static function () use ($run, $command): void {
        $state = new RunState(new RunCheckpoint($run, RunStatus::Running, 1, 1));
        $completed = $state->apply($command(RunStatus::Completed));
        H::rejects(static fn () => $completed->apply($command(RunStatus::WaitingAction, 2, key: 'late-provider')), ContractViolation::class);
        H::check($completed->checkpoint->status === RunStatus::Completed && count($completed->commands) === 1);
        H::check($completed->apply($command(RunStatus::Completed)) === $completed);
    },
    'V2ALL-STATES-POSITIVE' => static function () use ($run, $command, $tenant): void {
        $state = new RunState(new RunCheckpoint($run, RunStatus::Accepted, 1, 1));
        $preparing = $command(RunStatus::Preparing);
        $state = $state->apply($preparing);
        $state = $state->apply($command(RunStatus::Running, 2, key: 'start'));
        $state = $state->apply($command(RunStatus::WaitingExternal, 3, key: 'wait'));
        H::check($state->outcome($preparing)->checkpoint->status === RunStatus::Preparing);
        H::check($state->checkpoint->status === RunStatus::WaitingExternal);
        $cancel = (new RunState(new RunCheckpoint($run, RunStatus::Accepted, 1, 1)))
            ->apply($command(RunStatus::Cancelling));
        $cancel = $cancel->apply($command(RunStatus::Cancelled, 2, key: 'finish-cancel', policy: new Ref($tenant, 'owner-policy')));
        H::check($cancel->checkpoint->status === RunStatus::Cancelled);
        H::check(!(new ReflectionClass($cancel))->hasProperty('delivery') && !(new ReflectionClass($cancel))->hasProperty('effects'));
    },
    'V2ALL-STATES-NEGATIVE' => static function () use ($run, $command): void {
        H::rejects(static fn () => (new RunState(new RunCheckpoint($run, RunStatus::Accepted, 1, 1)))->apply($command(RunStatus::Completed)), ContractViolation::class);
        H::rejects(static fn () => (new RunState(new RunCheckpoint($run, RunStatus::Cancelling, 1, 1)))->apply($command(RunStatus::Cancelled)), ContractViolation::class);
        H::check(array_intersect(['pending', 'ready', 'executing', 'succeeded', 'manual_resolution_required'],
            array_column(RunStatus::cases(), 'value')) === []);
        $unknown = (new RunCheckpoint($run, RunStatus::Cancelling, 1, 1))->recordUnknown(ErrorCode::EffectUnknown, 1, 1);
        H::rejects(static fn () => $unknown->transition(RunStatus::Cancelled, 2, 1), ContractViolation::class);
        H::check($unknown->unresolvedEffects && $unknown->status === RunStatus::Cancelling);
    },
    'V2ALL-STATES-FAULT' => static function () use ($run, $command): void {
        foreach ([RunStatus::WaitingAction, RunStatus::WaitingConfirmation, RunStatus::WaitingExternal, RunStatus::Cancelling] as $status) {
            $state = new RunState(new RunCheckpoint($run, $status, 3, 2));
            H::rejects(static fn () => $state->apply($command(RunStatus::Failed, 2, 2)), ContractViolation::class, ErrorCode::OptimisticConflict);
            H::rejects(static fn () => $state->apply($command(RunStatus::Failed, 3, 1)), ContractViolation::class, ErrorCode::StaleClaim);
        }
        $state = new RunState(new RunCheckpoint($run, RunStatus::Running, 1, 1));
        $wait = $command(RunStatus::WaitingAction);
        $state = $state->apply($wait);
        $state = $state->apply($command(RunStatus::Running, 2, key: 'resume'));
        H::check($state->apply($wait) === $state);
        H::check($state->outcome($wait)->checkpoint->version === 2);
        H::rejects(static fn () => $state->apply($command(RunStatus::WaitingExternal)), DomainException::class);
    },
    'run.command-bindings' => static function () use ($run, $tenant, $hasher, $command): void {
        $state = new RunState(new RunCheckpoint($run, RunStatus::Running, 1, 1));
        $otherRun = new RunCommand(new RunId($tenant, 'other'), new Ref($tenant, 'cmd'), new Ref($tenant, 'step'), RunStatus::Completed, 1, 1, null, $hasher);
        H::rejects(static fn () => $state->apply($otherRun), ContractViolation::class, ErrorCode::IntegrityConflict);
        H::rejects(static fn () => new RunCommand($run, new Ref(new TenantId('tenant-b'), 'cmd'), new Ref($tenant, 'step'), RunStatus::Completed, 1, 1, null, $hasher), DomainException::class);
        $saved = $state->apply($command(RunStatus::Completed));
        H::check($saved->apply($command(RunStatus::Completed, 9, 9)) === $saved);
        $numeric = $command(RunStatus::Completed, key: '1');
        H::check($state->apply($numeric)->outcome($numeric)->command === $numeric);
        H::rejects(static fn () => $saved->apply(new RunCommand($run, new Ref($tenant, 'command-1'), new Ref($tenant, 'different-step'), RunStatus::Completed, 1, 1, null, $hasher)), DomainException::class);
    },
];
if (realpath($argv[0] ?? '') === __FILE__) {
    H::run($cases, 'unit', 'unit');
}
return $cases;
