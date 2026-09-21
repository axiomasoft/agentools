<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

enum ToolIdempotencyCapability: string
{
    case NativeKey = 'native_key';
    case ReadBeforeWrite = 'read_before_write';
    case Compensatable = 'compensatable';
    case AtMostOnceOnly = 'at_most_once_only';
    case Unknown = 'unknown';
}
