<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Inbox;

use AgenToolsPlatform\Core\Common\SourceIdentity;

/** Internal SQL identity encoding. Independent of injected payload/JCS hashing. */
final class SourceKey
{
    public static function event(SourceIdentity $source): string
    {
        // Length prefix makes delimiter-bearing opaque IDs unambiguous. The source
        // kind is the other UNIQUE column; tenant is always an explicit predicate.
        $instance = $source->instance->value;
        return strlen($instance) . ':' . $instance . ':' . $source->eventId;
    }
}
