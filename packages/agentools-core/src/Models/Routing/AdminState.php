<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

enum AdminState: string
{
    case Enabled = 'enabled';
    case Draining = 'draining';
    case Disabled = 'disabled';
    case Quarantined = 'quarantined';
    case Retired = 'retired';
    case IncidentBlocked = 'incident_blocked';
}
