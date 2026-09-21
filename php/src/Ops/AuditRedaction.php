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
    public function assertPayload(array $payload): void
    {
        foreach ($payload as $key => $value) {
            $name = (string) $key;
            $allowed = isset(self::ALLOW[$name])
                || str_ends_with($name, '_id')
                || str_ends_with($name, '_digest');
            if (!$allowed || in_array($name, self::DENY, true)) {
                throw EventStoreFailure::auditForbiddenField($name);
            }
            if ($name === 'amount_minor' && is_float($value)) {
                throw EventStoreFailure::auditForbiddenField($name);
            }
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
        $this->assertPayload($clean);
        $document['payload'] = $clean;

        return $document;
    }
}
