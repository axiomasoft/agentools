<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

/** Host-owned live signal; transport checks again before send and during stream reads. */
interface CancellationSignal
{
    public function isCancellationRequested(): bool;
}
