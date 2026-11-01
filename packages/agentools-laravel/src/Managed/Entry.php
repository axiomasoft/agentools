<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

enum Entry: string
{
    case Managed = 'managed';
    case Custom = 'custom';
    case Raw = 'raw';
    case Build = 'build';
    case Stream = 'stream';
    case Voice = 'voice';
    case Background = 'background';
    case DirectAction = 'direct-action';
}
