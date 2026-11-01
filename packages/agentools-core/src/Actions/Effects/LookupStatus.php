<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Effects;

/** Absent is authoritative not-sent, never a timeout or a missing probe. */
enum LookupStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Absent = 'absent';
    case Unknown = 'unknown';
    case Unsupported = 'unsupported';
}
