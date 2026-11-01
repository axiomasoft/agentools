<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

enum PublicationStatus: string
{
    case Published = 'published';
    case Deprecated = 'deprecated';
    case Retired = 'retired';
    case Revoked = 'revoked';
    case Unknown = 'unknown';
}
