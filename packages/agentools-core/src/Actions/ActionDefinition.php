<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;

/** Code-created, content-addressed definition. JSON strings prevent nested object mutation. */
final readonly class ActionDefinition
{
    public DefinitionRef $ref;
    public string $inputSchema;
    public string $outputSchema;

    /**
     * @param list<string> $requiredScopes
     * @param list<string> $auditFields
     */
    public function __construct(
        string $id,
        string $version,
        public string $ownerHandlerId,
        string $inputSchema,
        string $outputSchema,
        public EffectClass $effect,
        public array $requiredScopes,
        public bool $requiresConfirmation,
        public int $timeoutMs,
        public int $maxAttempts,
        public int $retryBackoffMs,
        public int $deadlineMs,
        public string $idempotencyCapability,
        public ?int $costBoundMinor,
        public string $residency,
        public string $retention,
        public string $redaction,
        public array $auditFields,
        public ?DefinitionRef $compensation,
        CanonicalJson $json,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $ownerHandlerId) !== 1
            || $timeoutMs < 1 || $maxAttempts < 1 || $retryBackoffMs < 0 || $deadlineMs < $timeoutMs
            || max($timeoutMs, $maxAttempts, $retryBackoffMs, $deadlineMs, $costBoundMinor ?? 0) > 9007199254740991
            || ($costBoundMinor !== null && $costBoundMinor < 0)
            || !in_array($idempotencyCapability, ['native_key', 'read_before_write', 'none', 'unknown'], true)
            || $residency === '' || $retention === '' || $redaction === '') {
            throw new \InvalidArgumentException('Invalid code binding or execution policy');
        }
        foreach ([$requiredScopes, $auditFields] as $values) {
            self::validateNames($values);
        }
        foreach ([$inputSchema, $outputSchema] as $schema) {
            if (!json_decode($schema, false, 512, JSON_THROW_ON_ERROR) instanceof \stdClass) {
                throw new \InvalidArgumentException('A JSON schema object is required');
            }
        }
        $this->inputSchema = $json->canonicalizeJson($inputSchema);
        $this->outputSchema = $json->canonicalizeJson($outputSchema);
        $hash = new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:' . hash('sha256', $json->canonicalize([
            'revision' => 'platform/action-definition/v1', 'id' => $id, 'version' => $version,
            ...$this->metadata(),
        ])));
        $this->ref = new \AgenToolsPlatform\Core\Profiles\DefinitionRef($id, $version, $hash);
    }

    /** Validate the runtime boundary even for PHP callers without static type checks. */
    private static function validateNames(mixed $values): void
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw new \InvalidArgumentException('Expected a policy field list');
        }
        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/^[a-z][a-z0-9._:-]{0,127}$/D', $value) !== 1 || isset($seen[$value])) {
                throw new \InvalidArgumentException('Expected unique valid policy fields');
            }
            $seen[$value] = true;
        }
    }

    /**
     * Common source of execution/model/admin views.
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'owner_handler_id' => $this->ownerHandlerId,
            'input_schema' => json_decode($this->inputSchema, false, 512, JSON_THROW_ON_ERROR),
            'output_schema' => json_decode($this->outputSchema, false, 512, JSON_THROW_ON_ERROR),
            'effect' => $this->effect->value, 'required_scopes' => $this->requiredScopes,
            'requires_confirmation' => $this->requiresConfirmation,
            'timeout_ms' => $this->timeoutMs, 'max_attempts' => $this->maxAttempts,
            'retry_backoff_ms' => $this->retryBackoffMs, 'deadline_ms' => $this->deadlineMs,
            'idempotency_capability' => $this->idempotencyCapability, 'cost_bound_minor' => $this->costBoundMinor,
            'residency' => $this->residency, 'retention' => $this->retention, 'redaction' => $this->redaction,
            'audit_fields' => $this->auditFields, 'compensation' => $this->compensation?->toArray(),
        ];
    }
}
