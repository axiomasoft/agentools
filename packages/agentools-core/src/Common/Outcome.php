<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

enum Outcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
