<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Error\ToolExecutionException;

final class IdempotencyFailure extends ToolExecutionException
{
    public static function inFlight(string $key): self
    {
        return new self(new ToolError(
            ToolErrorCode::IdempotencyInFlight->value,
            ErrorCategory::CONFLICT,
            true,
            'Work for this idempotency key is already in flight.',
            context: ['idempotency_key' => $key],
        ));
    }

    public static function keyReuse(string $key): self
    {
        return new self(new ToolError(
            ToolErrorCode::IdempotencyKeyReuse->value,
            ErrorCategory::VALIDATION,
            false,
            'The idempotency key was reused with different arguments.',
            context: ['idempotency_key' => $key],
        ));
    }

    public static function lostLease(): self
    {
        return new self(new ToolError(
            ToolErrorCode::IdempotencyLostLease->value,
            ErrorCategory::CONFLICT,
            false,
            'The idempotency lease is no longer held.',
        ));
    }

    public static function ambiguousEffect(): self
    {
        return new self(new ToolError(
            ToolErrorCode::IdempotencyAmbiguousEffect->value,
            ErrorCategory::PRECONDITION,
            false,
            'The previous attempt left an ambiguous external effect.',
        ));
    }

    public static function capabilityUnknown(string $tool): self
    {
        return new self(new ToolError(
            ToolErrorCode::IdempotencyCapabilityUnknown->value,
            ErrorCategory::PRECONDITION,
            false,
            'High-risk tools require a known idempotency capability.',
            context: ['tool' => $tool],
        ));
    }
}
