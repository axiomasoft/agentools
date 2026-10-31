<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

enum VisionDetail: string
{
    case Auto = 'auto';
    case Low = 'low';
    case High = 'high';
}
