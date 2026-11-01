<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

/** An opaque host input handle. Identity, consent, options and credentials are never queued. */
final readonly class Input
{
    public function __construct(public string $ref, public Entry $entry = Entry::Managed)
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,255}\z/D', $ref) !== 1) {
            throw new \InvalidArgumentException('A bounded host input reference is required');
        }
    }
}
