<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

/** Process health only: contains no tenant/user state and survives scoped-container flushes. */
final class LifecycleHealth
{
    private bool $poisoned = false;
    public function poison(): void { $this->poisoned = true; }
    public function assertUsable(): void { if ($this->poisoned) { throw new LifecycleFailure(); } }
}
