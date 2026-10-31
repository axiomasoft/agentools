<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Runtime;

use AgenToolsPlatform\Core\Common\{RunId, RunStatus, TurnId};

/** Intake status/checkpoint, not a claimed worker token or Action journal. */
final readonly class StoredRun
{
    /** @param array<string, mixed> $checkpoint */
    public function __construct(
        public RunId $id,
        public TurnId $turn,
        public RunStatus $status,
        public int $version,
        public int $fencingEpoch,
        public array $checkpoint,
    ) {
    }
}
