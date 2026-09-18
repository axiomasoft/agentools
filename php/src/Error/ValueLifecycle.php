<?php

declare(strict_types=1);

namespace AgenTools\Error;

/** Core open-enum member stage: Active, Deprecated, or Removed after the deprecation window. */
enum ValueLifecycle: string
{
    case Active = 'active';
    case Deprecated = 'deprecated';
    case Removed = 'removed';
}
