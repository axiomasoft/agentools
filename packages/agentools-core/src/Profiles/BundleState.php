<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

enum BundleState: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Published = 'published';
    case Deprecated = 'deprecated';
    case Retired = 'retired';
}
