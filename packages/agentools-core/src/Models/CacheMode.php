<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

enum CacheMode: string
{
    case Bypass = 'bypass';
    case Prefer = 'prefer';
    case Require = 'require';
}
