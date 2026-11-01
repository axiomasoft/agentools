<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

use AgenToolsPlatform\Core\Common\InputPayload;
use AgenToolsPlatform\Core\Context\ContextSnapshot;

final readonly class Prepared
{
    public function __construct(public InputPayload $payload, public ContextSnapshot $snapshot) {}
}
