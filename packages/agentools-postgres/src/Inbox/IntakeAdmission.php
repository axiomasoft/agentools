<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Inbox;

use AgenToolsPlatform\Core\Security\TrustedInput;
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Local SQL precondition on the intake connection; no external IO is allowed. */
interface IntakeAdmission
{
    public function assertCurrent(Connection $db, TrustedInput $input): void;
}
