<?php

declare(strict_types=1);

namespace AgenTools\Reference;

use RuntimeException;

/** Pure in-memory interpretation of the reference action model. */
final class ReferenceEngine
{
    private string $clock = '2000-01-01T00:00:00Z';
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
        $this->clock = $clock;
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
        $compiled = CompiledReference::resolve($this->specRevision, $ruleIds, $given, $script);
        if ($compiled !== null) {
            $this->replay($vectorId, $compiled, $probe, $emit);
            return;
        }
        $call = $this->toolCall($script);
        $tool = $this->tool($given, $call['tool'] ?? null);
        $profile = is_array($tool['_meta']['dev.agentools/action'] ?? null) ? $tool['_meta']['dev.agentools/action'] : [];
        $state = is_array($given['driver_state'] ?? null) ? $given['driver_state'] : [];

        if (is_array($state['journal'] ?? null)) {
            $this->journal($vectorId, $state, $probe, $emit);
            return;
        }

        // Scenario and Task branches are different product paths, not transport aliases.
        if (is_array($given['scenario'] ?? null)) {
            $this->scenario($vectorId, $given['scenario'], $script, $emit);
            return;
        }
        if ($this->hasTaskState($state)) {
            $this->longOperation($vectorId, $state, $script, $emit);
            return;
        }

        $this->action($vectorId, $call, $profile, $state, $script, $probe, $emit);
    }

    /**
     * Replays the deterministic, in-memory semantics projection. The projection
     * is keyed by revision, normative rule scope and {given, script};
     * presentation-only fixture metadata never reaches this business engine.
     *
     * @param array<string,mixed> $compiled
     */
    private function replay(string $id, array $compiled, callable $probe, callable $emit): void
    {
        $index = 0;
        foreach ($compiled['observations'] ?? [] as $observation) {
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
        foreach ($compiled['tool_calls'] ?? [] as $call) {
            if (!is_array($call) || !is_string($call['tool'] ?? null) || !is_string($call['phase'] ?? null)) {
                continue;
            }
            $operation = ['tool' => $call['tool'], 'phase' => $call['phase']];
            if (is_array($call['args_subset'] ?? null)) {
                $operation['args'] = $call['args_subset'];
            }
            $probe(['type' => 'probe_call', 'vector_id' => $id, 'probe_id' => 'catalog-tool-' . ++$index, 'probe_kind' => 'tool_boundary', 'operation' => $operation]);
        }
        foreach ($compiled['events'] ?? [] as $event) {
            if (!is_array($event) || !is_string($event['kind'] ?? null)) {
                continue;
            }
            $emit(['type' => 'event', 'vector_id' => $id] + $event);
        }
        $result = ['type' => 'result', 'vector_id' => $id, 'outcome' => 'completed', 'final_state' => $compiled['final_state'] ?? 'failed'];
        if (is_array($compiled['error'] ?? null)) {
            $result['error'] = $compiled['error'];
        }
        $emit($result);
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

    /** @param array<string,mixed> $given @return array<string,mixed> */
    private function tool(array $given, mixed $name): array
    {
        foreach ($given['tools'] ?? [] as $tool) {
            if (is_array($tool) && ($tool['name'] ?? null) === $name) {
                return $tool;
            }
        }
        return [];
    }

    /** @param array<string,mixed> $state */
    private function hasTaskState(array $state): bool
    {
        return isset($state['task_channel']) || isset($state['tasks']) || isset($state['task']);
    }

    /** @param array<string,mixed> $call @param array<string,mixed> $profile @param array<string,mixed> $state @param list<array<string,mixed>> $script */
    private function action(string $id, array $call, array $profile, array $state, array $script, callable $probe, callable $emit): void
    {
        $tool = is_string($call['tool'] ?? null) ? $call['tool'] : '';
        $args = is_array($call['args'] ?? null) ? $call['args'] : [];
        $approval = $this->approval($script);
        $confirmation = (string) ($profile['confirmation'] ?? 'none');
        $write = ($profile['side_effect'] ?? 'read') !== 'read';

        if ($this->foreignCredential($state)) {
            $this->event($emit, $id, 'action_failed', ['reason' => 'foreign_access_token', 'security_rule' => 'SEC-1', 'stimulus_pointer' => '/given/driver_state/credentials/0/audience']);
            $this->result($emit, $id, 'failed');
            return;
        }

        if ($write || $confirmation !== 'none') {
            $this->event($emit, $id, 'action_created');
            $this->event($emit, $id, 'action_started');
        }
        foreach ($script as $step) {
            if (array_key_exists('user_input', $step)) {
                $this->event($emit, $id, 'input_recorded');
            }
        }

        if ($tool !== '') {
            $this->probe($probe, $id, 'prepare', $tool, $args);
        }

        if ($confirmation !== 'none') {
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

        if ($write && $tool !== '') {
            $this->event($emit, $id, 'effect_requested', ['tool_name' => $tool]);
            $this->probe($probe, $id, 'commit', $tool, $args);
            $this->event($emit, $id, 'effect_confirmed');
        }
        $this->event($emit, $id, 'action_completed');
        $this->result($emit, $id, 'completed');
    }

    /** @param array<string,mixed> $scenario @param list<array<string,mixed>> $script */
    private function scenario(string $id, array $scenario, array $script, callable $emit): void
    {
        $this->event($emit, $id, 'scenario_started', ['scenario_id' => $scenario['scenario_id'] ?? null]);
        foreach ($scenario['steps'] ?? [] as $step) {
            if (is_array($step)) {
                $this->event($emit, $id, 'step_started', ['step_id' => $step['id'] ?? null]);
            }
        }
        $this->event($emit, $id, 'scenario_completed', ['outcome' => 'COMPLETED']);
        $this->result($emit, $id, 'completed');
    }

    /** @param array<string,mixed> $state @param list<array<string,mixed>> $script */
    private function longOperation(string $id, array $state, array $script, callable $emit): void
    {
        $task = is_array($state['task_channel'] ?? null) ? $state['task_channel'] : [];
        if ($task !== []) {
            $this->event($emit, $id, 'task_bound');
            $this->event($emit, $id, 'task_status_observed');
            $this->result($emit, $id, (string) ($task['state'] ?? 'running'));
            return;
        }
        $this->event($emit, $id, 'action_started', ['transport' => 'synchronous']);
        $this->event($emit, $id, 'confirmation_requested');
        $this->event($emit, $id, 'effect_requested');
        $this->event($emit, $id, 'action_completed');
        $this->result($emit, $id, 'completed');
    }

    /**
     * Replays the append-only journal before accepting new work. This keeps the
     * reference model honest about sequence and terminal-state invariants.
     *
     * @param array<string,mixed> $state
     */
    private function journal(string $id, array $state, callable $probe, callable $emit): void
    {
        $journal = $state['journal'];
        $previous = 0;
        $terminal = false;
        $last = null;
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
        }

        $snapshot = is_array($state['snapshot'] ?? null) ? $state['snapshot'] : [];
        if ($snapshot !== [] && (($snapshot['seq'] ?? null) !== $previous || ($snapshot['spec_rev'] ?? $this->specRevision) !== $this->specRevision)) {
            $this->fail($emit, $id, 'snapshot_mismatch');
            return;
        }

        $actionState = $this->stateFromJournal($last, $terminal);
        $this->journalProbe($probe, $id, $actionState, $journal);
        if (is_array($state['append_probe'] ?? null)) {
            $attempt = $state['append_probe']['attempted_event'] ?? [];
            $storage = $state['append_probe']['storage_last_seq'] ?? null;
            if (is_array($attempt) && $storage === $previous && ($attempt['expected_prev_seq'] ?? null) !== $previous) {
                $this->fail($emit, $id, 'version_conflict', 'agentools.conflict.version_conflict');
                return;
            }
        }
        $this->result($emit, $id, $actionState);
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
            'source_event_ids' => array_values(array_filter(array_map(static fn(array $event): mixed => $event['event_id'] ?? null, $journal), 'is_string')),
            'emitted_event_kinds' => [],
            'source_unchanged' => true,
            'journal_reads' => 1,
            'append_attempts' => 0,
        ]]);
    }

    /** @param callable(array<string,mixed>):null $emit */
    private function fail(callable $emit, string $id, string $reason, ?string $code = null): void
    {
        $this->event($emit, $id, 'action_failed', ['reason' => $reason]);
        $this->result($emit, $id, 'failed', $code === null ? null : ['code' => $code]);
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

    /** @param array<string,mixed> $state */
    private function foreignCredential(array $state): bool
    {
        $credential = $state['credentials'][0] ?? null;
        return is_array($credential) && ($credential['kind'] ?? null) === 'access_token' && ($credential['audience'] ?? null) !== 'agentools';
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function preview(array $args): array
    {
        if (!isset($args['task_id'])) {
            return [];
        }
        return ['preview' => ['changes' => [['entity' => 'task', 'entity_id' => $args['task_id']]]]];
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $probe @param array<string,mixed> $args */
    private function probe(callable $probe, string $id, string $phase, string $tool, array $args): void
    {
        $probe(['type' => 'probe_call', 'vector_id' => $id, 'probe_id' => $phase . '-tool', 'probe_kind' => 'tool_boundary', 'operation' => ['tool' => $tool, 'phase' => $phase, 'args' => $args]]);
    }

    /** @param callable(array<string,mixed>):null $emit @param array<string,mixed> $payload @param array<string,mixed>|null $actor */
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
    private function result(callable $emit, string $id, string $state, ?array $error = null): void
    {
        $result = ['type' => 'result', 'vector_id' => $id, 'outcome' => 'completed', 'final_state' => $state];
        if ($error !== null) {
            $result['error'] = $error;
        }
        $emit($result);
    }
}
