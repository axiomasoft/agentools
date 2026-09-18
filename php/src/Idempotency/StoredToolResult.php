<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

use AgenTools\Error\ToolError;

/** A completed tool result, successful or erroneous, preserved without re-execution. */
final readonly class StoredToolResult
{
    /** @param array<string, mixed>|null $value */
    public function __construct(public ?array $value = null, public ?ToolError $error = null)
    {
        if (($value === null) === ($error === null)) {
            throw new \InvalidArgumentException('A completed tool result contains exactly one of value or error.');
        }
    }
}
