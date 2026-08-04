<?php

declare(strict_types=1);

namespace AgenTools\Error;

/** A completed tool call; business failures are returned as data for model recovery. */
final readonly class ToolExecutionResult
{
    /** @param array<string, mixed>|null $value */
    private function __construct(public ?array $value, public ?ToolError $error)
    {
        if (($value === null) === ($error === null)) {
            throw new \InvalidArgumentException('A tool execution result must contain exactly one outcome.');
        }
    }

    /** @param array<string, mixed> $value */
    public static function success(array $value): self
    {
        return new self($value, null);
    }

    public static function failure(ToolError $error): self
    {
        return new self(null, $error);
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }
}
