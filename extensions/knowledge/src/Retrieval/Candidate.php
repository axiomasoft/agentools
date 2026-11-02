<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

use AgenToolsPlatform\Knowledge\Integrity;

/** Reference only. Adapter text, citations, roles and permission claims are never accepted. */
final readonly class Candidate
{
    public function __construct(public ReleasePin $release, public string $artifact,
        public int $start, public int $end, public float $score)
    {
        Integrity::text($artifact);
        if ($start < 0 || $end <= $start || !is_finite($score)) {
            throw new \InvalidArgumentException('Finite score and valid byte range required');
        }
    }

    public function key(): string { return Integrity::digest([$this->release, $this->artifact, $this->start, $this->end]); }
}
