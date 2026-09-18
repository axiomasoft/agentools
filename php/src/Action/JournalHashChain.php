<?php

declare(strict_types=1);

namespace AgenTools\Action;

use AgenTools\Schema\Canonicalizer;

/** Computes and verifies the optional audit-grade event hash chain. */
final readonly class JournalHashChain
{
    public function __construct(private Canonicalizer $canonicalizer)
    {
    }

    public function hash(ActionEvent $event): string
    {
        return 'sha-256:' . hash('sha256', $this->canonicalizer->canonicalize($event->hashInput()));
    }
}
