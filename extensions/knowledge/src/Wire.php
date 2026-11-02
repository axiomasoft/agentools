<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

/** Strict storage decoder. Never PHP-unserializes owner-controlled data. */
final class Wire
{
    /** @return array<string,mixed> */
    public static function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) { throw new \UnexpectedValueException('Object required'); }
        $result = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) { throw new \UnexpectedValueException('String key required'); }
            $result[$key] = $entry;
        }
        return $result;
    }

    /** @param array<string,mixed> $data */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) { throw new \UnexpectedValueException('String required: ' . $key); }
        return $value;
    }

    /** @param array<string,mixed> $data */
    public static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) { throw new \UnexpectedValueException('Integer required: ' . $key); }
        return $value;
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) { throw new \UnexpectedValueException('List required'); }
        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) { throw new \UnexpectedValueException('String list required'); }
            $result[] = $entry;
        }
        return $result;
    }

    /** @return non-empty-list<string> */
    public static function nonemptyStrings(mixed $value): array
    {
        $result = self::strings($value);
        if ($result === []) { throw new \UnexpectedValueException('Nonempty list required'); }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public static function objects(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) { throw new \UnexpectedValueException('Object list required'); }
        return array_map(self::object(...), $value);
    }

    /** @return array{float,float,float,float} */
    public static function box(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 4) { throw new \UnexpectedValueException('Box required'); }
        $out = [];
        foreach ($value as $number) {
            if ((!is_float($number) && !is_int($number)) || !is_finite((float) $number)) { throw new \UnexpectedValueException('Finite coordinate required'); }
            $out[] = (float) $number;
        }
        return [$out[0], $out[1], $out[2], $out[3]];
    }
}
