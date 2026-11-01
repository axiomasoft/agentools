<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

enum Classification: string
{
    case Public = 'public';
    case Private = 'private';
    case Restricted = 'restricted';
}
