<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Outbox;

final readonly class OutboxClaim
{
    public function __construct(public string $id, public WakeSignal $signal, public int $version,
        public int $epoch, public string $owner, public int $attempt, public string $claimedUntil)
    {
    }
}
