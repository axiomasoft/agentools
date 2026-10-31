<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

enum EffectClass: string
{
    case Read = 'read';
    case Write = 'write';
    case External = 'external';
}
