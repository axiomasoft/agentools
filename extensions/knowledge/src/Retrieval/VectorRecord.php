<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

use AgenToolsPlatform\Knowledge\Integrity;

/** Data in one explicitly declared semantic index partition. */
final readonly class VectorRecord
{
    /** @param list<float> $vector */
    public function __construct(public string $tenant, public string $releaseHash, public string $artifact, public array $vector)
    {
        Integrity::text($tenant); Integrity::text($artifact); Integrity::hash($releaseHash);
    }
}
