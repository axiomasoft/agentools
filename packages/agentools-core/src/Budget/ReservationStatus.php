<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Budget;

enum ReservationStatus: string
{
    case Pending = 'pending'; case Active = 'active'; case Settling = 'settling'; case Settled = 'settled';
    case Releasing = 'releasing'; case Released = 'released'; case Unknown = 'unknown';
}
