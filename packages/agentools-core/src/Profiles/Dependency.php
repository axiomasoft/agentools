<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

final readonly class Dependency
{
    public function __construct(public ResourceRef $ref, public Provenance $provenance) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['resource' => $this->ref->toArray(), 'provenance' => $this->provenance->toArray()];
    }
}
