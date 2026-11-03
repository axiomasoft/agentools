<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Qualification;

/** Strict document decoding shared by immutable qualification records. */
final class Data
{
    /** @return array<string,mixed> */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) { throw new \InvalidArgumentException('Qualification object required'); }
        foreach ($value as $key => $_) { if (!is_string($key)) { throw new \InvalidArgumentException('Object keys required'); } }
        return $value;
    }
    /** @param array<string,mixed> $data */
    public static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') { throw new \InvalidArgumentException('Qualification string required: '.$key); }
        return $value;
    }
    /** @param array<string,mixed> $data */
    public static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value < 0 || $value > 9007199254740991) { throw new \InvalidArgumentException('Bounded integer required'); }
        return $value;
    }
    public static function finite(mixed $value): int|float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) { throw new \InvalidArgumentException('Finite numeric measurement required'); }
        return $value;
    }
    /** @return list<string> */
    public static function strings(mixed $value, bool $nonempty = false): array
    {
        if (!is_array($value) || !array_is_list($value) || ($nonempty && $value === [])) { throw new \InvalidArgumentException('String list required'); }
        foreach ($value as $item) { if (!is_string($item) || $item === '') { throw new \InvalidArgumentException('Nonempty string required'); } }
        return $value;
    }
    /** @return array<string,string> */
    public static function map(mixed $value): array
    {
        $data = self::object($value);
        foreach ($data as $key => $item) { if ($key === '' || !is_string($item) || $item === '') { throw new \InvalidArgumentException('String map required'); } }
        return $data;
    }
    /** @param list<string> $values */
    public static function set(array $values): bool { return count($values) === count(array_unique($values)); }
}
