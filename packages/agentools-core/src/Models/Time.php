<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\UtcInstant;

/** Fixed-width comparison preserves microseconds without reading a clock. */
final class Time
{
    public static function compare(UtcInstant $left, UtcInstant $right): int
    {
        return strcmp(self::key($left), self::key($right));
    }

    private static function key(UtcInstant $instant): string
    {
        $value = $instant->value;
        $fraction = strlen($value) > 20 ? substr($value, 20, -1) : '';
        return substr($value, 0, 19) . '.' . substr($fraction . '000000', 0, 6);
    }
}
