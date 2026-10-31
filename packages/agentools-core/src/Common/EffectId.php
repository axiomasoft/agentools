<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

final readonly class EffectId extends \AgenToolsPlatform\Core\Common\ScopedId
{
    protected const string KIND = 'effect';
    protected const bool DATED_OPAQUE = true;
}
