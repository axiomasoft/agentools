<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

enum Certainty: string
{
    case Known = 'known';
    case Unknown = 'unknown';
}
