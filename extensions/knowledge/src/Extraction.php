<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

final readonly class Extraction
{
    /** @param list<Artifact> $artifacts */
    public function __construct(public string $extractorVersion, public array $artifacts)
    {
        Integrity::text($extractorVersion);
        if (!array_is_list($artifacts)) { throw new \InvalidArgumentException('Artifact list required'); }
    }
}
