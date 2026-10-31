<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

/** Explicit UTC value, with no clock read or implicit timezone. */
final readonly class UtcInstant
{
    public string $value;

    public function __construct(string $value)
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?Z$/D', $value, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            throw new \InvalidArgumentException('Expected a valid UTC instant');
        }
        $fraction = rtrim($parts[7] ?? '', '0');
        $this->value = substr($value, 0, 19) . ($fraction === '' ? '' : '.' . $fraction) . 'Z';
    }
}
