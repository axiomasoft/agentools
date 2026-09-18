<?php

declare(strict_types=1);

namespace AgenTools\Error;

use AgenTools\Schema\RawDocument;

/** Accepts a message with unknown members and preserves the original bytes for replay. */
final readonly class MustIgnoreEnvelope
{
    public function __construct(private RawDocument $document)
    {
    }

    public static function fromJson(string $json): self
    {
        return new self(RawDocument::fromJson($json));
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(new RawDocument($payload));
    }

    public function toJson(): string
    {
        return $this->document->toJson();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->document->toArray();
    }
}
