<?php

declare(strict_types=1);

namespace AgenTools\Error;

/** A structured tool-execution error; protocol errors deliberately use another channel. */
final readonly class ToolError
{
    /** @param array<string, mixed>|null $context @param array<string, mixed>|null $exampleOfValidCall */
    public function __construct(
        public string $code,
        public string $category,
        public bool $retryable,
        public string $title,
        public ?string $detail = null,
        public ?array $context = null,
        public ?array $exampleOfValidCall = null,
    ) {
        if ($title === '') {
            throw new \InvalidArgumentException('A tool error title must not be empty.');
        }

        if (preg_match('/^.+\.([a-z0-9_]+)\.([a-z][a-z0-9_]*)$/', $code, $match) !== 1 || $match[1] !== $category) {
            throw new \InvalidArgumentException('A tool error code must have the form <vendor>.<category>.<slug>.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'code' => $this->code,
            'category' => $this->category,
            'retryable' => $this->retryable,
            'title' => $this->title,
            'detail' => $this->detail,
            'context' => $this->context,
            'example_of_valid_call' => $this->exampleOfValidCall,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
