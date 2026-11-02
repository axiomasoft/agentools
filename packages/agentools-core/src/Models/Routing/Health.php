<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

enum Health: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';
}
