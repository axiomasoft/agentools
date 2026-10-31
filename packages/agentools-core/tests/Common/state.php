<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Common\ActionPhaseMapping;
use AgenToolsPlatform\Core\Common\ActionReceipt;
use AgenToolsPlatform\Core\Common\ActionId;
use AgenToolsPlatform\Core\Common\Certainty;
use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\RunCheckpoint;
use AgenToolsPlatform\Core\Common\RunId;
use AgenToolsPlatform\Core\Common\RunStatus;
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\SpecFixtures;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/SpecFixtures.php';
$run = new RunId(new TenantId('tenant-a'), 'run-1');
$cases = [
    'run.graph-contract' => static function (): void {
        $document = SpecFixtures::document(dirname(__DIR__, 4) . '/spec/platform/v1/alc-compatibility.json');
        $graph = SpecFixtures::object($document['run_fsm'] ?? null);
        $actual = [];
        foreach (RunStatus::cases() as $from) {
            $allowed = [];
            foreach (RunStatus::cases() as $to) {
                if ($from->allows($to)) {
                    $allowed[] = $to->value;
                }
            }
            $expected = $graph[$from->value] ?? null;
            if (!is_array($expected)) {
                throw new RuntimeException('Missing Run state contract');
            }
            sort($expected);
            sort($allowed);
            H::check($allowed === $expected);
            $actual[$from->value] = $allowed;
        }
        H::check(count($actual) === count($graph));
    },
    'run.closed-fsm' => static function () use ($run): void {
        $record = new RunCheckpoint($run, RunStatus::Accepted, 1, 1);
        $record = $record->transition(RunStatus::Preparing, 1, 1)->transition(RunStatus::Running, 2, 1);
        $completed = $record->transition(RunStatus::Completed, 3, 1);
        H::check($completed->status === RunStatus::Completed && $completed->version === 4);
        H::check(!(new ReflectionEnum(RunStatus::class))->hasCase('ReconciliationRequired'));
        H::rejects(static fn () => (new RunCheckpoint($run, RunStatus::Accepted, 1, 1))->transition(RunStatus::Completed, 1, 1), ContractViolation::class);
    },
    'run.terminal-late-callback' => static function () use ($run): void {
        foreach ([RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled] as $terminal) {
            $record = new RunCheckpoint($run, $terminal, 4, 1);
            H::rejects(static fn () => $record->transition(RunStatus::Running, 4, 1), ContractViolation::class);
            H::rejects(static fn () => $record->recordUnknown(ErrorCode::EffectUnknown, 4, 1), ContractViolation::class);
        }
    },
    'run.stale-version-epoch' => static function () use ($run): void {
        $record = new RunCheckpoint($run, RunStatus::Running, 4, 2);
        H::rejects(static fn () => $record->transition(RunStatus::Completed, 3, 2), ContractViolation::class, ErrorCode::OptimisticConflict);
        H::rejects(static fn () => $record->transition(RunStatus::Completed, 4, 1), ContractViolation::class, ErrorCode::StaleClaim);
    },
    'run.unknown-not-terminal' => static function () use ($run): void {
        $unknown = (new RunCheckpoint($run, RunStatus::Running, 1, 1))->recordUnknown(ErrorCode::EffectUnknown, 1, 1);
        H::check($unknown->certainty === Certainty::Unknown && $unknown->unresolvedEffects);
        foreach ([RunStatus::Failed, RunStatus::Completed] as $terminal) {
            H::rejects(static fn () => $unknown->transition($terminal, 2, 1), ContractViolation::class);
        }
        H::rejects(static fn () => new RunCheckpoint($run, RunStatus::Failed, 1, 1, Certainty::Unknown), InvalidArgumentException::class);
    },
    'run.cancel-unresolved' => static function () use ($run): void {
        $unknown = (new RunCheckpoint($run, RunStatus::Running, 1, 1))->recordUnknown(ErrorCode::EffectUnknown, 1, 1);
        $cancelling = $unknown->transition(RunStatus::Cancelling, 2, 1);
        H::rejects(static fn () => $cancelling->transition(RunStatus::Cancelled, 3, 1), ContractViolation::class);
        $resolved = $cancelling->resolved(new Ref($run->tenant, 'owner-resolution-1'), 3, 1);
        H::check($resolved->transition(RunStatus::Cancelled, 4, 1)->status === RunStatus::Cancelled);
    },
    'run.resolution-tenant' => static function () use ($run): void {
        $record = new RunCheckpoint($run, RunStatus::WaitingExternal, 1, 1, Certainty::Unknown);
        H::rejects(static fn () => $record->resolved(new Ref(new TenantId('tenant-b'), 'resolution'), 1, 1), ContractViolation::class);
    },
    'action.alc-projection' => static function () use ($run): void {
        $document = SpecFixtures::document(dirname(__DIR__, 4) . '/spec/platform/v1/alc-compatibility.json');
        $projection = $document['projection'] ?? null;
        if (!is_array($projection)) {
            throw new RuntimeException('Missing ALC projection');
        }
        foreach ($projection as $value) {
            $row = SpecFixtures::object($value);
            $phase = SpecFixtures::string($row['internal_status'] ?? null);
            $status = SpecFixtures::string($row['wire_status'] ?? null);
            H::check(ActionPhaseMapping::project($phase, $status, true) === $status);
        }
        $sdk = array_map(static fn (\AgenTools\Action\ActionStatus $status): string => $status->value, \AgenTools\Action\ActionStatus::cases());
        H::check($sdk === $document['wire_statuses']);
        $receipt = new ActionReceipt(new ActionId($run->tenant, 'action-1'), 'completed', 1);
        H::check($receipt->toArray()['wire_status'] === 'completed');
    },
    'action.mapping-not-dispatch-authority' => static function (): void {
        H::rejects(static fn () => ActionPhaseMapping::project('ready', 'running'), ContractViolation::class);
        H::rejects(static fn () => ActionPhaseMapping::project('succeeded', 'applying'), ContractViolation::class);
        H::rejects(static fn () => ActionPhaseMapping::project('manual_resolution_required', 'reconciliation_required'), InvalidArgumentException::class);
        H::rejects(static fn () => ActionPhaseMapping::project('waiting_external', 'running'), InvalidArgumentException::class);
    },
];
if (realpath($argv[0] ?? '') === __FILE__) {
    H::run($cases);
}
return $cases;
