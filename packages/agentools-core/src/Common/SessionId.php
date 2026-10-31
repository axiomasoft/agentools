<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

final readonly class SessionId extends \AgenToolsPlatform\Core\Common\ScopedId
{
    protected const string KIND = 'session';
}
