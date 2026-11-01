<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

enum SafetyFloor: int
{
    case Baseline = 0;
    case Strict = 1;
}
