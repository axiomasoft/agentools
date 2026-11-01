<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

/** Profile pins describe behavior, never current authority. */
enum UseBoundary: string
{
    case NewSession = 'new_session';
    case Invocation = 'invocation';
    case Action = 'action';
    case Disclosure = 'disclosure';
    case Migration = 'migration';
}
