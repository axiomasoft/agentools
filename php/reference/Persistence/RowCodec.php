<?php

declare(strict_types=1);

namespace AgenTools\Reference\Persistence;

/** @internal */
final class RowCodec
{
    /** @param array<mixed, mixed> $row */
    public static function string(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new \InvalidArgumentException('Expected string column ' . $key . '.');
        }

        return $row[$key];
    }

    /** @param array<mixed, mixed> $row */
    public static function int(array $row, string $key): int
    {
        if (!isset($row[$key]) || !is_int($row[$key])) {
            throw new \InvalidArgumentException('Expected integer column ' . $key . '.');
        }

        return $row[$key];
    }

    /**
     * @param array<mixed, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function object(array $row, string $key): array
    {
        if (!isset($row[$key]) || !is_array($row[$key])) {
            throw new \InvalidArgumentException('Expected object column ' . $key . '.');
        }
        /** @var array<string, mixed> $value */
        $value = $row[$key];

        return $value;
    }
}
