<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

enum Support: string
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';
}
