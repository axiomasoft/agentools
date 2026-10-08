<?php

declare(strict_types=1);

namespace AgenTools\Ops;

use AgenTools\Error\EventStoreFailure;

/** Redacts audit documents before AuditSink export. Tokens and PII never leave. */
final class AuditRedaction
{
    private const ALLOW = [
        'tool_name' => true, 'side_effect' => true, 'verdict' => true,
        'limit_name' => true, 'limit_value' => true, 'observed' => true,
        'scenario_id' => true, 'outcome' => true, 'step_id' => true,
        'tokens_in' => true, 'tokens_out' => true, 'amount_minor' => true,
        'currency' => true, 'price_table_version' => true, 'trace' => true,
    ];

    private const DENY = [
        'arguments', 'args', 'prompt', 'prompts', 'token', 'email', 'pii', 'password',
    ];

    /** @param array<string, mixed> $payload */
    public function assertPayload(array $payload, ?string $kind = null): void
    {
        foreach ($payload as $key => $value) {
            $name = (string) $key;
            $allowed = isset(self::ALLOW[$name])
                || str_ends_with($name, '_id')
                || str_ends_with($name, '_digest');
            if (!$allowed || in_array($name, self::DENY, true)) {
                throw EventStoreFailure::auditForbiddenField($name);
            }
            if ($name === 'trace') {
                if (!is_array($value) || !isset($value['trace_id']) || array_diff(array_keys($value), ['trace_id', 'span_id']) !== []) {
                    throw EventStoreFailure::auditForbiddenField($name);
                }
                foreach ($value as $field => $id) {
                    $length = $field === 'trace_id' ? 32 : 16;
                    if (!is_string($id) || !preg_match('/^[0-9a-f]{' . $length . '}$/D', $id)) {
                        throw EventStoreFailure::auditForbiddenField('trace.' . $field);
                    }
                }
                continue;
            }
            $numeric = in_array($name, ['tokens_in', 'tokens_out', 'amount_minor', 'limit_value', 'observed'], true);
            if ($numeric ? (!is_int($value) || abs($value) > 9007199254740991 || ($name !== 'amount_minor' && $value < 0)) : (!is_string($value) || $value === '')) {
                throw EventStoreFailure::auditForbiddenField($name);
            }
        }
        $required = match ($kind) {
            'audit.policy_evaluated' => ['tool_name', 'side_effect', 'verdict'],
            'audit.cost_recorded' => ['tokens_in', 'tokens_out', 'amount_minor', 'currency', 'price_table_version'],
            'audit.limit_exceeded' => ['limit_name', 'limit_value', 'observed'],
            'audit.scenario_run_finished' => ['scenario_id', 'outcome'],
            'audit.compensation_executed' => ['scenario_id', 'step_id'],
            null => [],
            default => throw EventStoreFailure::auditForbiddenField('kind'),
        };
        foreach ($required as $field) {
            if (!array_key_exists($field, $payload)) {
                throw EventStoreFailure::auditForbiddenField($field);
            }
        }
        foreach (['side_effect' => ['read', 'draft', 'write', 'irreversible'], 'verdict' => ['allow', 'ask', 'deny'], 'outcome' => ['COMPLETED', 'FAILED', 'COMPENSATED', 'COMPENSATION_FAILED']] as $field => $values) {
            if (isset($payload[$field]) && !in_array($payload[$field], $values, true) && !($field === 'side_effect' && is_string($payload[$field]) && preg_match('/^[a-z][a-z0-9]*\.[a-z0-9_]+$/D', $payload[$field]))) {
                throw EventStoreFailure::auditForbiddenField($field);
            }
        }
        if (isset($payload['currency']) && (!is_string($payload['currency']) || !preg_match('/^[A-Z]{3}$/D', $payload['currency']))) {
            throw EventStoreFailure::auditForbiddenField('currency');
        }
        if ($kind === 'audit.limit_exceeded' && $payload['observed'] <= $payload['limit_value']) {
            throw EventStoreFailure::auditForbiddenField('observed');
        }
        if (($payload['outcome'] ?? null) === 'COMPENSATION_FAILED' && !isset($payload['stopped_at_step_id'])) {
            throw EventStoreFailure::auditForbiddenField('stopped_at_step_id');
        }
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function redactDocument(array $document): array
    {
        $payload = $document['payload'] ?? [];
        if (!is_array($payload)) {
            $payload = [];
        }
        /** @var array<string, mixed> $clean */
        $clean = [];
        foreach ($payload as $key => $value) {
            $name = (string) $key;
            if (in_array($name, self::DENY, true)) {
                continue;
            }
            $clean[$name] = $value;
        }
        $this->assertPayload($clean, is_string($document['kind'] ?? null) ? $document['kind'] : null);
        $document['payload'] = $clean;

        return $document;
    }
}
