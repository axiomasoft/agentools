<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter;

use AgenToolsPlatform\Core\Common\UtcInstant;

final class SystemClock
{
    public function now(): UtcInstant
    {
        return new UtcInstant((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'));
    }
}
