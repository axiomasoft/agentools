<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Outbox;

use AgenToolsPlatform\Core\Common\RunId;

/** Transport only. A receiver must load and claim the committed Run, never accept a new Turn. */
final readonly class WakeSignal
{
    public function __construct(public RunId $run, public string $eventKey, public string $payloadHash)
    {
    }
}
