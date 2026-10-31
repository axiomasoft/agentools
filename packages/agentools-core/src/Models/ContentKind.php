<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

enum ContentKind: string
{
    case Text = 'text';
    case Ref = 'ref';
    case Image = 'image';
    case Audio = 'audio';
}
