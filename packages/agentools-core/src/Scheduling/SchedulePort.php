<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Scheduling;

use AgenToolsPlatform\Core\Common\{AcceptedTurn, Ref, UtcInstant};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Security\TrustedInput;

/** Optional trigger intake. Implementations atomically bind one planned occurrence to ordinary intake. */
interface SchedulePort
{
    /** Identity is tenant/schedule/planned UTC, independent of retry time or backend. */
    public function accept(Ref $schedule, int $configVersion, UtcInstant $plannedAt,
        TrustedInput $input, ContextSnapshot $snapshot): AcceptedTurn;
}
