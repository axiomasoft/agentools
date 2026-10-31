<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Models;

final class CancellationSwitch implements \AgenToolsPlatform\Core\Models\CancellationSignal
{
    public bool $requested = false;
    public function isCancellationRequested(): bool { return $this->requested; }
}
