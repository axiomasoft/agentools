<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

final class Integrity
{
    public static function text(string $value): void
    {
        if (trim($value) === '' || preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('Nonempty UTF-8 value required');
        }
    }

    public static function hash(string $value): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('SHA-256 required');
        }
    }

    /** @param list<string> $values */
    public static function names(array $values): void
    {
        if (!array_is_list($values) || count(array_unique($values)) !== count($values)) {
            throw new \InvalidArgumentException('Unique list required');
        }
        foreach ($values as $value) { self::text($value); }
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
