<?php

declare(strict_types=1);

namespace AgenTools\Error;

/** A structured tool-execution error; protocol errors deliberately use another channel. */
final readonly class ToolError
{
    /**
     * @param array<string, mixed>|null $context
     * @param array<string, mixed>|null $exampleOfValidCall
     */
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

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        foreach (['code', 'category', 'title'] as $required) {
            if (!isset($payload[$required]) || !is_string($payload[$required])) {
                throw new \InvalidArgumentException(sprintf('%s must be a string.', $required));
            }
        }
        if (!isset($payload['retryable']) || !is_bool($payload['retryable'])) {
            throw new \InvalidArgumentException('retryable must be a boolean.');
        }
        $detail = null;
        if (array_key_exists('detail', $payload)) {
            if (!is_string($payload['detail'])) {
                throw new \InvalidArgumentException('detail must be a string.');
            }
            $detail = $payload['detail'];
        }
        $context = self::optionalObject($payload, 'context');
        $example = self::optionalObject($payload, 'example_of_valid_call');
        ErrorCategoryOpenEnum::parse($payload['category']);

        return new self(
            $payload['code'],
            $payload['category'],
            $payload['retryable'],
            $payload['title'],
            $detail,
            $context,
            $example,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function optionalObject(array $payload, string $key): ?array
    {
        if (!array_key_exists($key, $payload)) {
            return null;
        }
        if (!is_array($payload[$key]) || array_is_list($payload[$key])) {
            throw new \InvalidArgumentException(sprintf('%s must be an object.', $key));
        }
        $object = [];
        foreach ($payload[$key] as $member => $value) {
            if (!is_string($member)) {
                throw new \InvalidArgumentException(sprintf('%s must be an object.', $key));
            }
            $object[$member] = $value;
        }

        return $object;
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
