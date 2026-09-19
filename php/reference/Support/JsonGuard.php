<?php

declare(strict_types=1);

namespace AgenTools\Reference\Support;

/** Small helpers for decoding untyped harness and vector payloads. */
final class JsonGuard
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function listOfMaps(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $maps = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $maps[] = self::stringKeyedMap($item);
            }
        }

        return $maps;
    }

    /**
     * @param array<mixed, mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function stringKeyedMap(array $value): array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }
}
