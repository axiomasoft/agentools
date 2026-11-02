<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

/** No text or disclosure grant. Every reference is resolved and authorized again at disclosure. */
final readonly class Selection
{
    /** @param list<Candidate> $candidates */
    public function __construct(public string $binding, public string $status, public string $code,
        public array $candidates, public int $expiresAt) {}
}
