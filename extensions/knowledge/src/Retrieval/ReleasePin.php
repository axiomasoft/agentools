<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

use AgenToolsPlatform\Knowledge\{Integrity, Material};

/** Exact allowlist and program metadata supplied by the trusted host catalog. */
final readonly class ReleasePin
{
    /** @param list<string> $programs */
    public function __construct(public string $corpus, public string $id, public string $hash,
        public Material $material, public array $programs = [])
    {
        foreach ([$corpus, $id] as $value) { Integrity::text($value); }
        Integrity::hash($hash); Integrity::names($programs);
    }

    public function key(): string { return Integrity::digest($this); }
}
