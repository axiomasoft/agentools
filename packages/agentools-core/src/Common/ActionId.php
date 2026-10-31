<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

final readonly class ActionId extends \AgenToolsPlatform\Core\Common\ScopedId
{
    protected const string KIND = 'action';
    protected const bool DATED_OPAQUE = true;
}
