<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

enum OperationalRole: int
{
    case Host = 0;
    case Session = 1;
    case Live = 2;
}
