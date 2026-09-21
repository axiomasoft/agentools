<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Idempotency\StoredToolResult;

/** Downstream read-before-write probe. Absence degrades to at-most-once. */
interface ExternalEffectProbe
{
    public function find(string $tool, string $idempotencyKey): ?StoredToolResult;
}
