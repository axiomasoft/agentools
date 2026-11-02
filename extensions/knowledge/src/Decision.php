<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

enum Decision: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Unknown = 'unknown';
}
