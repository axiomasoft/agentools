<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

/** Test reporting only; this package never grants runtime authorization. */
final readonly class CaseResult
{
    public function __construct(public string $id, public string $status)
    {
        if ($id === '' || !in_array($status, ['passed', 'failed', 'skipped'], true)) {
            throw new \InvalidArgumentException('A case needs an ID and an explicit outcome');
        }
    }
}
