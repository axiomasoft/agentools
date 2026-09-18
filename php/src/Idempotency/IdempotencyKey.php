<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;

/** A caller-generated at1 key. The SDK never derives it from call arguments. */
final readonly class IdempotencyKey
{
    public function __construct(public string $value)
    {
        if (preg_match('/^at1:[A-Za-z0-9_-]{8,128}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Idempotency key must match ^at1:[A-Za-z0-9_-]{8,128}$.');
        }
    }

    /**
     * Use at the idempotent-commit tool boundary after reading
     * _meta["dev.agentools/action"]["idempotency_key"].
     */
    public static function required(?string $value): self
    {
        if ($value === null || $value === '') {
            throw new IdempotencyFailure(new ToolError(
                ToolErrorCode::IdempotencyKeyMissing->value,
                ErrorCategory::VALIDATION,
                false,
                'An idempotency key is required for this commit.',
            ));
        }

        return new self($value);
    }
}
