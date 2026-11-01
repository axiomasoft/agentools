<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

enum Tone: string
{
    case Neutral = 'neutral';
    case Friendly = 'friendly';
    case Concise = 'concise';
}
