<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

use AgenToolsPlatform\Core\Security\TrustedInput;
use AgenToolsPlatform\Postgres\Inbox\IntakeAdmission;
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Adapter-local transaction participant, with no public alternate persistence method on Store. */
final readonly class IntakeBridge implements IntakeAdmission
{
    /** @param \Closure(Connection, TrustedInput):void $admit */
    public function __construct(private \Closure $admit) {}
    public function assertCurrent(Connection $db, TrustedInput $input): void { ($this->admit)($db,$input); }
}
