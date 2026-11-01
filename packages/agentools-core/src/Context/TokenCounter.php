<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\Ref;

/** Versioned host counting adapter. Unknown exact counts must advertise a conservative bound. */
interface TokenCounter
{
    public function deployment(): Ref;
    public function version(): string;
    public function confidence(): string;
    public function count(string $rendered): int;
}
