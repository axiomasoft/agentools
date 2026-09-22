<?php

declare(strict_types=1);

/**
 * Assembles semantic-profile/v1 from production PHP constants (manual DTO gate).
 */
function agentools_semantic_profile(): array
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $categoryDefaultRetryable = [
        'validation' => false,
        'permission' => false,
        'not_found' => false,
        'precondition' => false,
        'conflict' => true,
        'unavailable' => true,
        'budget' => false,
        'internal' => false,
    ];

    $rows = [];
    foreach (\AgenTools\Error\ToolErrorCode::cases() as $case) {
        $code = $case->value;
        $parts = explode('.', $code, 3);
        $category = $parts[1] ?? 'internal';
        $rows[] = [
            'code' => $code,
            'category' => $category,
            'default_retryable' => $categoryDefaultRetryable[$category] ?? false,
            'source' => 'php/src/Error/ToolErrorCode.php',
        ];
    }
    usort($rows, static fn(array $a, array $b): int => $a['code'] <=> $b['code']);

    $caseRetryability = [];
    foreach (glob(dirname(__DIR__, 2) . '/fixtures/hardening/*-v1.json') ?: [] as $path) {
        $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        foreach ($document['cases'] ?? [] as $case) {
            $code = (string) ($case['semantic_code'] ?? '');
            $retryable = match ($code) {
                \AgenTools\Error\ToolErrorCode::IdempotencyInFlight->value,
                \AgenTools\Error\ToolErrorCode::VersionConflict->value => true,
                default => false,
            };
            $caseRetryability[] = [
                'case_id' => $case['id'],
                'semantic_code' => $code,
                'category' => $code === 'ok' ? 'success' : (explode('.', $code)[1] ?? 'internal'),
                'retryable' => $retryable,
            ];
        }
    }
    usort($caseRetryability, static fn(array $a, array $b): int => $a['case_id'] <=> $b['case_id']);

    $states = [];
    foreach ([
        'action' => \AgenTools\Action\ActionStatus::cases(),
        'proposal' => \AgenTools\Confirmation\ProposalState::cases(),
        'idempotency' => \AgenTools\Idempotency\DeduplicationState::cases(),
    ] as $domain => $cases) {
        foreach ($cases as $case) {
            $states[] = ['key' => $domain . ':' . $case->value, 'domain' => $domain, 'value' => $case->value];
        }
    }
    usort($states, static fn(array $a, array $b): int => $a['key'] <=> $b['key']);

    $transition = static fn(string $id, ?string $from, string $event, string $to): array => [
        'transition' => $id, 'from' => $from, 'event' => $event, 'to' => $to,
    ];
    $transitions = [
        $transition('ALC-T1', null, 'action_created', 'created'),
        $transition('ALC-T2', 'created', 'input_recorded', 'created'),
        $transition('ALC-T3', 'created', 'epoch_started', 'created'),
        $transition('ALC-T4', 'created', 'action_started', 'running'),
        $transition('ALC-T5', 'running', 'input_recorded', 'running'),
        $transition('ALC-T6', 'running', 'epoch_started', 'running'),
        $transition('ALC-T7', 'running', 'confirmation_requested', 'awaiting_confirmation'),
        $transition('ALC-T8', 'running', 'effect_requested', 'applying'),
        $transition('ALC-T9', 'running', 'action_completed', 'completed'),
        $transition('ALC-T10', 'running', 'action_failed', 'failed'),
        $transition('ALC-T11', 'awaiting_confirmation', 'confirmation_resolved', 'running'),
        $transition('ALC-T12', 'awaiting_confirmation', 'confirmation_resolved', 'rejected'),
        $transition('ALC-T13', 'awaiting_confirmation', 'confirmation_resolved', 'rejected'),
        $transition('ALC-T14', 'awaiting_confirmation', 'confirmation_resolved', 'expired'),
        $transition('ALC-T15', 'awaiting_confirmation', 'confirmation_requested', 'awaiting_confirmation'),
        $transition('ALC-T16', 'awaiting_confirmation', 'epoch_started', 'awaiting_confirmation'),
        $transition('ALC-T17', 'awaiting_confirmation', 'action_failed', 'failed'),
        $transition('ALC-T18', 'applying', 'effect_confirmed', 'running'),
        $transition('ALC-T19', 'applying', 'effect_failed', 'running'),
        $transition('ALC-T20', 'applying', 'effect_requested', 'applying'),
        $transition('ALC-T21', 'applying', 'epoch_started', 'applying'),
        $transition('ALC-T22', 'applying', 'action_failed', 'failed'),
        $transition('ALC-T23', 'running', 'task_bound', 'running'),
        $transition('ALC-T24', 'applying', 'task_bound', 'applying'),
        $transition('ALC-T25', 'running', 'task_binding_lost', 'running'),
        $transition('ALC-T26', 'applying', 'task_binding_lost', 'reconciliation_required'),
        $transition('ALC-T27', 'reconciliation_required', 'effect_confirmed', 'running'),
        $transition('ALC-T28', 'reconciliation_required', 'effect_failed', 'running'),
        $transition('ALC-T29', 'reconciliation_required', 'epoch_started', 'reconciliation_required'),
        $transition('ALC-T30', 'reconciliation_required', 'action_failed', 'failed'),
        $transition('ALC-T31', 'created', 'cancellation_requested', 'created'),
        $transition('ALC-T32', 'running', 'cancellation_requested', 'running'),
        $transition('ALC-T33', 'awaiting_confirmation', 'cancellation_requested', 'awaiting_confirmation'),
        $transition('ALC-T34', 'applying', 'cancellation_requested', 'applying'),
        $transition('ALC-T35', 'reconciliation_required', 'cancellation_requested', 'reconciliation_required'),
        $transition('ALC-T36', 'created', 'action_cancelled', 'cancelled'),
        $transition('ALC-T37', 'running', 'action_cancelled', 'cancelled'),
        $transition('ALC-T38', 'awaiting_confirmation', 'action_cancelled', 'cancelled'),
    ];

    $limits = new \AgenTools\Scenario\ExpressionLimits();

    return [
        'schema_version' => 'semantic-profile/v1',
        'spec_revision' => '2026-09',
        'implementation' => 'php',
        'error_codes' => $rows,
        'case_retryability' => $caseRetryability,
        'states' => $states,
        'transitions' => $transitions,
        'envelope_versions' => [[
            'envelope' => 'confirmation_token',
            'version' => \AgenTools\Confirmation\TokenEnvelope::VERSION,
            'prefix' => \AgenTools\Confirmation\TokenEnvelope::PREFIX,
        ]],
        'bounds' => [
            ['bound' => 'baggage.max_bytes', 'value' => \AgenTools\Ops\BaggageRefs::MAX_BYTES],
            ['bound' => 'baggage.max_items', 'value' => \AgenTools\Ops\BaggageRefs::MAX_ITEMS],
            ['bound' => 'event_store.max_batch', 'value' => \AgenTools\Persistence\EventStoreSupport::MAX_BATCH],
            ['bound' => 'event_store.max_page', 'value' => \AgenTools\Persistence\EventStoreSupport::MAX_PAGE],
            ['bound' => 'expression.max_collection_size', 'value' => $limits->maxCollectionSize],
            ['bound' => 'expression.max_depth', 'value' => $limits->maxDepth],
            ['bound' => 'expression.max_length', 'value' => $limits->maxLength],
            ['bound' => 'expression.max_visited_nodes', 'value' => $limits->maxVisitedNodes],
        ],
        'outcomes' => array_map(
            static fn(\AgenTools\Scenario\ScenarioOutcome $case): array => [
                'key' => 'scenario:' . $case->value, 'domain' => 'scenario', 'value' => $case->value,
            ],
            \AgenTools\Scenario\ScenarioOutcome::cases(),
        ),
        'capabilities' => array_map(
            static fn(\AgenTools\Idempotency\ToolIdempotencyCapability $case): array => ['capability' => $case->value],
            \AgenTools\Idempotency\ToolIdempotencyCapability::cases(),
        ),
        'canonicalization_profile' => 'RFC8785',
    ];
}

if (PHP_SAPI === 'cli' && basename((string) ($argv[0] ?? '')) === 'semantic_profile.php') {
    echo json_encode(agentools_semantic_profile(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
