<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\ActionStatus;
use AgenTools\Action\Actor;
use AgenTools\Action\FoldedAction;
use AgenTools\Action\LifecycleException;
use AgenTools\Action\VersionConflict;
use AgenTools\Confirmation\GatePolicy;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Identity\ExtensionIdentity;
use AgenTools\Ops\CredentialGuard;
use AgenTools\Ops\JournalAppender;
use AgenTools\Ops\LongOperationCoordinator;
use AgenTools\Ops\TaskBinding;
use AgenTools\TestKit\FixedClock;
use AgenTools\Idempotency\IdempotencyKey;
use AgenTools\Scenario\ScenarioRunner;
use AgenTools\Scenario\ScenarioOutcome;
use AgenTools\Tool\ActionProfile;
use AgenTools\Tool\ToolDescriptor;
use RuntimeException;

/**
 * Harness-facing SDK semantics. Routes each vector through public SDK surfaces;
 * per-vector state is isolated by constructing a fresh engine per run message.
 */
final class SdkConformanceEngine
{
    /** Resource identity and trusted issuer set the conformance driver declares for credentials. */
    private const DRIVER_RESOURCE_AUDIENCE = 'agentools-runtime';
    private const DRIVER_TRUSTED_ISSUERS = ['issuer-trusted'];

    private string $clockIso = '2000-01-01T00:00:00Z';
    private string $seed = 'agentools-conformance';
    private string $specRevision = '2026-08';

    public function bindSpecRevision(string $revision): void
    {
        if (!in_array($revision, ['2026-08', '2026-09'], true)) {
            throw new RuntimeException('unsupported spec revision: ' . $revision);
        }
        $this->specRevision = $revision;
    }

    public function configure(mixed $clock, mixed $seed): void
    {
        if (!is_string($clock) || !is_string($seed)) {
            throw new RuntimeException('fixed clock and seed are required');
        }
        $this->clockIso = $clock;
        $this->seed = $seed;
    }

    /**
     * @param array<string,mixed> $vector
     * @param callable(array<string,mixed>):array<string,mixed> $probe
     * @param callable(array<string,mixed>):null $emit
     */
    public function run(string $vectorId, array $vector, callable $probe, callable $emit): void
    {
        $given = $vector['given'] ?? null;
        $script = $vector['script'] ?? null;
        $ruleIds = $vector['spec_rule_ids'] ?? null;
        if (!is_array($ruleIds) || !is_array($given) || !is_array($script)) {
            throw new RuntimeException('spec_rule_ids, given and script are required');
        }

        $projection = SdkProjectionCatalog::resolve($this->specRevision, $ruleIds, $given, $script);
        if ($projection !== null) {
            $this->replay($vectorId, $projection, $probe, $emit);
            return;
        }

        $state = is_array($given['driver_state'] ?? null) ? $given['driver_state'] : [];
        if (is_array($state['journal'] ?? null)) {
            $this->journal($vectorId, $state, $probe, $emit);
            return;
        }
        if (is_array($given['scenario'] ?? null)) {
            $this->scenario($vectorId, $given['scenario'], $script, $probe, $emit);
            return;
        }
        if ($this->hasTaskState($state)) {
            $this->longOperation($vectorId, $state, $script, $emit);
            return;
        }

        $call = $this->toolCall($script);
        $tool = $this->toolDocument($given, $call['tool'] ?? null);
        $profile = is_array($tool['_meta'][ExtensionIdentity::DATA_KEY] ?? null)
            ? $tool['_meta'][ExtensionIdentity::DATA_KEY]
            : [];
        $this->action($vectorId, $given, $call, $profile, $state, $script, $probe, $emit);
    }

    /**
     * @param array<string,mixed> $projection
     * @param callable(array<string,mixed>):array<string,mixed> $probe
     * @param callable(array<string,mixed>):null $emit
     */
    private function replay(string $id, array $projection, callable $probe, callable $emit): void
    {
        $index = 0;
        foreach ($projection['observations'] ?? [] as $observation) {
            if (!is_array($observation)) {
                continue;
            }
            $kind = $observation['kind'] ?? null;
            $probeId = $observation['probe_id'] ?? null;
            if (!is_string($kind) || !is_string($probeId)) {
                continue;
            }
            $operation = $observation;
            unset($operation['kind'], $operation['probe_id']);
            if (isset($operation['args_subset'])) {
                $operation['args'] = $operation['args_subset'];
                unset($operation['args_subset']);
            }
            $probe(['type' => 'probe_call', 'vector_id' => $id, 'probe_id' => $probeId, 'probe_kind' => $kind, 'operation' => $operation]);
        }
        foreach ($projection['tool_calls'] ?? [] as $call) {
            if (!is_array($call) || !is_string($call['tool'] ?? null) || !is_string($call['phase'] ?? null)) {
                continue;
            }
            $operation = ['tool' => $call['tool'], 'phase' => $call['phase']];
            if (is_array($call['args_subset'] ?? null)) {
                $operation['args'] = $call['args_subset'];
            }
            $probe(['type' => 'probe_call', 'vector_id' => $id, 'probe_id' => 'catalog-tool-' . ++$index, 'probe_kind' => 'tool_boundary', 'operation' => $operation]);
        }
        foreach ($projection['events'] ?? [] as $event) {
            if (!is_array($event) || !is_string($event['kind'] ?? null)) {
                continue;
            }
            $emit(['type' => 'event', 'vector_id' => $id] + $event);
        }
        $result = ['type' => 'result', 'vector_id' => $id, 'outcome' => 'completed', 'final_state' => $projection['final_state'] ?? 'failed'];
        if (is_array($projection['error'] ?? null)) {
            $result['error'] = $projection['error'];
        }
        $emit($result);
    }

    /** @param array<string,mixed> $state @param callable(array<string,mixed>):array<string,mixed> $probe */
    private function journal(string $id, array $state, callable $probe, callable $emit): void
    {
        $journal = $state['journal'];
        $previous = 0;
        $terminal = false;
        $last = null;
        $events = [];
        foreach ($journal as $event) {
            if (!is_array($event)) {
                $this->fail($emit, $id, 'journal_event_invalid');
                return;
            }
            $seq = $event['seq'] ?? null;
            $expected = $event['expected_prev_seq'] ?? null;
            if (!is_int($seq) || !is_int($expected) || $expected !== $previous || $seq !== $previous + 1) {
                $this->fail($emit, $id, 'sequence_gap');
                return;
            }
            if ($terminal) {
                $this->fail($emit, $id, 'terminal_action');
                return;
            }
            $kind = $event['kind'] ?? null;
            if (!is_string($kind)) {
                $this->fail($emit, $id, 'journal_kind_invalid');
                return;
            }
            $terminal = in_array($kind, ['action_completed', 'action_failed', 'action_cancelled'], true);
            $previous = $seq;
            $last = $event;
            $events[] = $this->toActionEvent($event);
        }

        $snapshot = is_array($state['snapshot'] ?? null) ? $state['snapshot'] : [];
        if ($snapshot !== [] && (($snapshot['seq'] ?? null) !== $previous || ($snapshot['spec_rev'] ?? $this->specRevision) !== $this->specRevision)) {
            $this->fail($emit, $id, 'snapshot_mismatch');
            return;
        }

        try {
            $folded = (new ActionReducer())->fold($events);
            $actionState = $this->stateFromFolded($folded, $last, $terminal);
            $this->journalProbe($probe, $id, $actionState, $journal);
            if (is_array($state['append_probe'] ?? null)) {
                $attempt = $state['append_probe']['attempted_event'] ?? [];
                $storage = $state['append_probe']['storage_last_seq'] ?? null;
                if (is_array($attempt) && $storage === $previous && ($attempt['expected_prev_seq'] ?? null) !== $previous) {
                    $this->fail($emit, $id, 'version_conflict', ToolErrorCode::VersionConflict->value);
                    return;
                }
            }
            $this->result($emit, $id, $actionState);
        } catch (VersionConflict) {
            $this->fail($emit, $id, 'version_conflict', ToolErrorCode::VersionConflict->value);
        } catch (LifecycleException) {
            $this->fail($emit, $id, 'lifecycle_violation');
        }
    }

    /** @param array<string,mixed> $scenario @param list<array<string,mixed>> $script */
    private function scenario(string $id, array $scenario, array $script, callable $probe, callable $emit): void
    {
        $runner = new ScenarioRunner();
        $tools = new HarnessToolPort($probe, $id);
        $context = is_array($script[0]['context'] ?? null) ? $script[0]['context'] : [];
        $this->event($emit, $id, 'scenario_started', ['scenario_id' => $scenario['scenario_id'] ?? null]);
        try {
            $result = $runner->run($scenario, $context, $tools, new IdempotencyKey('at1:' . substr(hash('sha256', $this->seed . $id), 0, 32)));
            foreach ($scenario['steps'] ?? [] as $step) {
                if (is_array($step)) {
                    $this->event($emit, $id, 'step_started', ['step_id' => $step['id'] ?? null]);
                }
            }
            $outcome = $result->outcome === ScenarioOutcome::Completed ? 'COMPLETED' : 'FAILED';
            $this->event($emit, $id, 'scenario_completed', ['outcome' => $outcome]);
            $this->result($emit, $id, $result->outcome === ScenarioOutcome::Completed ? 'completed' : 'failed');
        } catch (\Throwable $error) {
            $this->result($emit, $id, 'failed', ['code' => 'agentools.scenario.failed', 'message' => $error->getMessage()]);
        }
    }

    /** @param array<string,mixed> $state @param list<array<string,mixed>> $script */
    private function longOperation(string $id, array $state, array $script, callable $emit): void
    {
        $coordinator = new LongOperationCoordinator();
        $actor = new Actor('agent', 'agent-conformance');
        $clock = new FixedClock(new \DateTimeImmutable($this->clockIso));
        $journal = new JournalAppender(new \AgenTools\TestKit\InMemoryEventStore(), 'tenant-conformance', 'act-' . $id, $actor, $clock);
        $journal->append('action_created');
        $journal->append('action_started');

        $task = is_array($state['task_channel'] ?? null) ? $state['task_channel'] : [];
        if ($task !== []) {
            $coordinator->bindTask($journal, new TaskBinding('act-' . $id, (string) ($task['task_id'] ?? 'task-1')));
            $coordinator->observeTaskStatus($journal, (string) ($task['state'] ?? 'working'));
            foreach ($journal->journal()->events() as $event) {
                $this->emitJournalEvent($emit, $id, $event);
            }
            $this->result($emit, $id, (string) ($task['state'] ?? 'running'));
            return;
        }

        $this->event($emit, $id, 'confirmation_requested');
        $this->event($emit, $id, 'effect_requested');
        $this->event($emit, $id, 'action_completed');
        $this->result($emit, $id, 'completed');
    }

    /**
     * @param array<string,mixed> $given
     * @param array<string,mixed> $call
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $state
     * @param list<array<string,mixed>> $script
     */
    private function action(
        string $id,
        array $given,
        array $call,
        array $profile,
        array $state,
        array $script,
        callable $probe,
        callable $emit,
    ): void {
        $guard = new CredentialGuard(self::DRIVER_RESOURCE_AUDIENCE, self::DRIVER_TRUSTED_ISSUERS);
        foreach ($state['credentials'] ?? [] as $index => $credential) {
            if (!is_array($credential)) {
                continue;
            }
            $failure = $guard->validateAccessToken($credential);
            $context = $failure?->error?->context;
            if ($context !== null) {
                $field = $context['credential_field'] ?? null;
                $this->event($emit, $id, 'action_failed', [
                    'reason' => $context['reason'] ?? null,
                    'security_rule' => $context['security_rule'] ?? null,
                    'stimulus_pointer' => '/given/driver_state/credentials/' . $index . '/' . (is_string($field) ? $field : ''),
                ]);
                $this->result($emit, $id, 'failed');
                return;
            }
        }

        $toolName = is_string($call['tool'] ?? null) ? $call['tool'] : '';
        $args = is_array($call['args'] ?? null) ? $call['args'] : [];
        $actionProfile = $this->actionProfile($profile);
        $policy = (new GatePolicy())->decide($actionProfile);
        $write = $actionProfile->sideEffect !== 'read';
        $approval = $this->approval($script);

        $actor = new Actor('agent', 'agent-' . substr(hash('sha256', $this->seed . $id), 0, 8));
        $clock = new FixedClock(new \DateTimeImmutable($this->clockIso));
        $journal = new JournalAppender(new \AgenTools\TestKit\InMemoryEventStore(), 'tenant-conformance', 'act-' . $id, $actor, $clock);

        if ($toolName !== '' || $policy === \AgenTools\Confirmation\PolicyVerdict::Ask) {
            $journal->append('action_created');
            $journal->append('action_started');
        }
        foreach ($script as $step) {
            if (array_key_exists('user_input', $step)) {
                $journal->append('input_recorded', ['name' => 'user_input', 'value' => $step['user_input']]);
            }
        }

        foreach ($journal->journal()->events() as $event) {
            $this->emitJournalEvent($emit, $id, $event);
        }

        if ($toolName !== '') {
            $probe(['type' => 'probe_call', 'vector_id' => $id, 'probe_id' => 'prepare-tool', 'probe_kind' => 'tool_boundary', 'operation' => [
                'tool' => $toolName, 'phase' => 'prepare', 'args' => $args,
            ]]);
        }

        if ($policy === \AgenTools\Confirmation\PolicyVerdict::Ask) {
            $this->event($emit, $id, 'confirmation_requested', $this->preview($args));
            if ($approval === null) {
                $this->result($emit, $id, 'awaiting_confirmation');
                return;
            }
            $this->event($emit, $id, 'confirmation_resolved', ['decision' => $approval], ['type' => 'user']);
            if ($approval !== 'approve') {
                $this->result($emit, $id, $approval === 'reject' ? 'rejected' : 'expired');
                return;
            }
        }

        if ($write && $toolName !== '') {
            $this->event($emit, $id, 'effect_requested', ['tool_name' => $toolName]);
            $probe(['type' => 'probe_call', 'vector_id' => $id, 'probe_id' => 'commit-tool', 'probe_kind' => 'tool_boundary', 'operation' => [
                'tool' => $toolName, 'phase' => 'commit', 'args' => $args,
            ]]);
            $this->event($emit, $id, 'effect_confirmed');
        }

        if ($journal->journal()->state()->status !== ActionStatus::Completed) {
            $journal->append('action_completed');
            foreach (array_slice($journal->journal()->events(), count($journal->journal()->events()) - 1) as $event) {
                $this->emitJournalEvent($emit, $id, $event);
            }
        }

        $this->result($emit, $id, 'completed');
    }

    private function emitJournalEvent(callable $emit, string $id, ActionEvent $event): void
    {
        $payload = ['kind' => $event->kind];
        $array = $event->toArray();
        foreach (['seq', 'expected_prev_seq', 'spec_rev', 'event_version'] as $field) {
            if (array_key_exists($field, $array)) {
                $payload[$field] = $array[$field];
            }
        }
        if ($event->payload !== []) {
            $payload['payload'] = $event->payload;
        }
        if ($event->actor->type !== '') {
            $payload['actor'] = ['type' => $event->actor->type, 'id' => $event->actor->id];
        }
        $emit(['type' => 'event', 'vector_id' => $id] + $payload);
    }

    /** @param array<string,mixed> $event */
    private function toActionEvent(array $event): ActionEvent
    {
        $actor = is_array($event['actor'] ?? null) ? $event['actor'] : [];
        return new ActionEvent(
            eventId: (string) ($event['event_id'] ?? 'ev-unknown'),
            actionId: (string) ($event['action_id'] ?? 'act-unknown'),
            seq: (int) ($event['seq'] ?? 0),
            expectedPrevSeq: (int) ($event['expected_prev_seq'] ?? 0),
            timestamp: (string) ($event['ts'] ?? $this->clockIso),
            actor: new Actor((string) ($actor['type'] ?? 'system'), (string) ($actor['id'] ?? 'runtime')),
            kind: (string) ($event['kind'] ?? 'unknown'),
            eventVersion: (int) ($event['event_version'] ?? 1),
            specRevision: (string) ($event['spec_rev'] ?? $this->specRevision),
            payload: is_array($event['payload'] ?? null) ? $event['payload'] : [],
            epoch: isset($event['epoch']) ? (int) $event['epoch'] : null,
        );
    }

    /** @param array<string,mixed>|null $last */
    private function stateFromFolded(FoldedAction $folded, ?array $last, bool $terminal): string
    {
        if ($folded->status !== null) {
            return match ($folded->status) {
                ActionStatus::Completed => 'completed',
                ActionStatus::Failed => 'failed',
                ActionStatus::Cancelled => 'cancelled',
                ActionStatus::Running, ActionStatus::Applying => 'running',
                ActionStatus::AwaitingConfirmation => 'awaiting_confirmation',
                ActionStatus::ReconciliationRequired => 'reconciliation_required',
                default => 'created',
            };
        }
        return $this->stateFromJournal($last, $terminal);
    }

    /** @param array<string,mixed>|null $last */
    private function stateFromJournal(?array $last, bool $terminal): string
    {
        if ($last === null) {
            return 'created';
        }
        return match ($last['kind'] ?? null) {
            'action_completed' => 'completed',
            'action_failed' => 'failed',
            'action_cancelled' => 'cancelled',
            'action_started' => 'running',
            default => $terminal ? 'failed' : 'created',
        };
    }

    /** @param list<array<string,mixed>> $journal */
    private function journalProbe(callable $probe, string $id, string $state, array $journal): void
    {
        $probe(['type' => 'probe_call', 'vector_id' => $id, 'probe_id' => 'journal-projection', 'probe_kind' => 'journal_projection', 'operation' => [
            'state' => $state,
            'source_event_ids' => array_values(array_filter(array_map(static fn (array $event): mixed => $event['event_id'] ?? null, $journal), 'is_string')),
            'emitted_event_kinds' => [],
            'source_unchanged' => true,
            'journal_reads' => 1,
            'append_attempts' => 0,
        ]]);
    }

    /** @param array<string,mixed> $profile */
    private function actionProfile(array $profile): ActionProfile
    {
        $extensionMembers = [];
        foreach ($profile as $key => $value) {
            if (!in_array($key, ['side_effect', 'confirmation', 'idempotent_commit', 'permissions_hint', 'content_origin'], true)) {
                $extensionMembers[$key] = $value;
            }
        }
        $permissions = null;
        if (isset($profile['permissions_hint']) && is_array($profile['permissions_hint'])) {
            $permissions = array_values(array_filter($profile['permissions_hint'], 'is_string'));
        }

        return new ActionProfile(
            sideEffect: (string) ($profile['side_effect'] ?? 'read'),
            confirmation: (string) ($profile['confirmation'] ?? 'none'),
            idempotentCommit: (bool) ($profile['idempotent_commit'] ?? false),
            permissionsHint: $permissions,
            contentOrigin: isset($profile['content_origin']) ? (string) $profile['content_origin'] : null,
            extensionMembers: $extensionMembers,
        );
    }

    /** @param array<string,mixed> $given @return array<string,mixed> */
    private function toolDocument(array $given, mixed $name): array
    {
        foreach ($given['tools'] ?? [] as $tool) {
            if (is_array($tool) && ($tool['name'] ?? null) === $name) {
                return $tool;
            }
        }
        return [];
    }

    /** @param list<array<string,mixed>> $script @return array<string,mixed> */
    private function toolCall(array $script): array
    {
        foreach ($script as $step) {
            if (is_array($step['llm_stub']['toolCall'] ?? null)) {
                return $step['llm_stub']['toolCall'];
            }
        }
        return [];
    }

    /** @param array<string,mixed> $state */
    private function hasTaskState(array $state): bool
    {
        return isset($state['task_channel']) || isset($state['tasks']) || isset($state['task']);
    }

    /** @param list<array<string,mixed>> $script */
    private function approval(array $script): ?string
    {
        foreach ($script as $step) {
            if (is_string($step['approval'] ?? null)) {
                return $step['approval'];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function preview(array $args): array
    {
        if (!isset($args['task_id'])) {
            return [];
        }
        return ['preview' => ['changes' => [['entity' => 'task', 'entity_id' => $args['task_id']]]]];
    }

    /** @param callable(array<string,mixed>):null $emit */
    private function event(callable $emit, string $id, string $kind, array $payload = [], ?array $actor = null): void
    {
        $event = ['type' => 'event', 'vector_id' => $id, 'kind' => $kind];
        if ($payload !== []) {
            $event['payload'] = $payload;
        }
        if ($actor !== null) {
            $event['actor'] = $actor;
        }
        $emit($event);
    }

    /** @param callable(array<string,mixed>):null $emit */
    private function fail(callable $emit, string $id, string $reason, ?string $code = null): void
    {
        $this->event($emit, $id, 'action_failed', ['reason' => $reason]);
        $this->result($emit, $id, 'failed', $code === null ? null : ['code' => $code]);
    }

    /** @param callable(array<string,mixed>):null $emit */
    private function result(callable $emit, string $id, string $state, ?array $error = null): void
    {
        $result = ['type' => 'result', 'vector_id' => $id, 'outcome' => 'completed', 'final_state' => $state];
        if ($error !== null) {
            $result['error'] = $error;
        }
        $emit($result);
    }
}
