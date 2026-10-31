<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
