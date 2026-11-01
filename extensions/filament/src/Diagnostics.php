<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Optional, separately authorized encrypted owner store. No diagnostic access from the page. */
interface Diagnostics
{
    /** Resolve a resource-bound encrypted ref with retention enforcement. No arbitrary browser ref. */
    public function read(ExecutionContext $context, Target $target, \DateTimeImmutable $expiresAt): string;
    public function reset(): void;
}
