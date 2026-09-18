#!/usr/bin/env php
<?php

declare(strict_types=1);

$operations = [
    ['probe_id' => 'tool-spy', 'probe_kind' => 'tool_boundary', 'operation' => ['tool' => 'demo_probe', 'phase' => 'commit', 'calls' => 1, 'route' => 'default', 'args' => ['value' => 'x', 'extra' => true], 'actor_ids' => ['user-a'], 'credential_ids' => []]],
    ['probe_id' => 'journal-spy', 'probe_kind' => 'journal_projection', 'operation' => ['state' => 'completed', 'source_event_ids' => ['event-1'], 'emitted_event_kinds' => ['action_completed'], 'write_back_upcast' => false]],
    ['probe_id' => 'audit-spy', 'probe_kind' => 'audit_sink', 'operation' => ['record_count' => 1, 'attribute_names' => ['action_id'], 'baggage_count' => 0, 'baggage_bytes' => 0]],
    ['probe_id' => 'task-spy', 'probe_kind' => 'task_binding', 'operation' => ['task_id' => 'task-1', 'binding_id' => 'binding:task-1', 'state' => 'completed', 'input_required_operations' => []]],
    ['probe_id' => 'credential-spy', 'probe_kind' => 'credential_boundary', 'operation' => ['resource' => 'record-1', 'principal_ids' => ['user-a'], 'credential_ids' => []]],
    ['probe_id' => 'confirmation-spy', 'probe_kind' => 'confirmation_boundary', 'operation' => ['proposal_count' => 1, 'token_issue_count' => 1, 'token_consume_count' => 1, 'decision_event_count' => 1]],
];

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (($message['type'] ?? '') === 'start') {
        if (($message['harness_protocol_version'] ?? '') !== '1.1') {
            exit(11);
        }
        echo json_encode(['type' => 'ready', 'sdk' => 'fixture/probe', 'spec_rev' => $message['spec_rev'], 'declared_level' => 'L1']) . "\n";
    }
    if (($message['type'] ?? '') === 'run') {
        $expected = ['spec_rev', 'spec_rule_ids', 'conformance_level', 'given', 'script'];
        $actual = array_keys($message['vector'] ?? []);
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            exit(12);
        }
        foreach ($operations as $operation) {
            echo json_encode(['type' => 'probe_call', 'vector_id' => $message['vector_id']] + $operation) . "\n";
            $reply = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
            if (($reply['type'] ?? '') !== 'probe_result' || ($reply['probe_id'] ?? '') !== $operation['probe_id']) {
                exit(13);
            }
            if ($operation['probe_kind'] === 'tool_boundary' && ($reply['probe_result']['tool_result']['content'] ?? '') !== '{"ok":true}') {
                exit(14);
            }
        }
        echo json_encode(['type' => 'result', 'vector_id' => $message['vector_id'], 'outcome' => 'completed', 'final_state' => 'completed']) . "\n";
    }
    if (($message['type'] ?? '') === 'stop') {
        exit(0);
    }
}
