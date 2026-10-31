<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Anthropic;

final class Fields
{
    public static function text(\stdClass $object, string $key, bool $empty = false): string
    {
        $value = $object->{$key} ?? null;
        if (!is_string($value) || (!$empty && $value === '')) { throw new ProtocolFailure('invalid_string'); }
        return $value;
    }
    public static function object(\stdClass $object, string $key): \stdClass
    {
        $value = $object->{$key} ?? null;
        if (!$value instanceof \stdClass) { throw new ProtocolFailure('invalid_object'); }
        return $value;
    }
    /** @return list<mixed> */
    public static function items(\stdClass $object, string $key): array
    {
        $value = $object->{$key} ?? null;
        if (!is_array($value) || !array_is_list($value)) { throw new ProtocolFailure('invalid_list'); }
        return $value;
    }
    public static function index(\stdClass $object): int
    {
        $value = $object->index ?? null;
        if (!is_int($value) || $value < 0 || $value > 4096) { throw new ProtocolFailure('invalid_block_index'); }
        return $value;
    }
}