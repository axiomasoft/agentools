<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

final readonly class EmbeddingSpace
{
    public function __construct(public string $provider, public string $model, public string $revision,
        public int $dimensions, public string $normalization, public string $indexVersion)
    {
        foreach ([$provider, $model, $revision, $normalization, $indexVersion] as $value) { Integrity::text($value); }
        if ($dimensions < 1) { throw new \InvalidArgumentException('Positive dimensions required'); }
    }

    public function key(): string { return Integrity::digest($this); }

    public function assertCompatible(self $other): void
    {
        if ($this->key() !== $other->key()) { throw new \DomainException('Embedding space mismatch'); }
    }
}
