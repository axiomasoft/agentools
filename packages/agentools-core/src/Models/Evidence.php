<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

enum Evidence: string
{
    case Unverified = 'unverified';
    case Documented = 'documented';
    case ProbeVerified = 'probe_verified';
}
