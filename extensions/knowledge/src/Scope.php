<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

/** Supplied by the trusted host, never constructed from model/browser claims. */
final readonly class Scope
{
    /** @param list<string> $scopes */
    public function __construct(public string $tenant, public string $subject, public string $audience,
        public string $purpose, public array $scopes)
    {
        foreach ([$tenant, $subject, $audience, $purpose] as $value) { Integrity::text($value); }
        Integrity::names($scopes);
    }

    public function matches(Material $material): bool
    {
        return $this->tenant === $material->tenant && in_array($this->audience, $material->audiences, true)
            && array_diff($material->scopes, $this->scopes) === [];
    }
}
