<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Profiles;

enum Operation: string
{
    case Inspect = 'inspect';
    case Draft = 'draft';
    case Validate = 'validate';
    case Publish = 'publish';
    case Assign = 'assign';
    case Deprecate = 'deprecate';
    case Retire = 'retire';
    case Migrate = 'migrate';
}
