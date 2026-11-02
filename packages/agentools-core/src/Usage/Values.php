<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

use AgenToolsPlatform\Core\Common\UtcInstant;

/** Closed JSON validation shared by the additive usage and admission codecs. */
final class Values
{
    /** @return array<string,mixed> */
    public static function object(mixed $v): array
    {
        if (!is_array($v)) { throw new \InvalidArgumentException('Object required'); }
        foreach ($v as $key=>$_) { if (!is_string($key)) { throw new \InvalidArgumentException('Object keys required'); } }
        return $v;
    }
    /**
     * @param array<string,mixed> $v
     * @param list<string> $keys
     */
    public static function keys(array $v, array $keys): void
    { if (array_diff(array_keys($v),$keys) !== [] || array_diff($keys,array_keys($v)) !== []) { throw new \InvalidArgumentException('Unknown or missing field'); } }
    public static function text(mixed $v): string
    { if (!is_string($v) || trim($v) === '') { throw new \InvalidArgumentException('Nonempty string required'); } return $v; }
    public static function optionalText(mixed $v): ?string { return $v === null ? null : self::text($v); }
    public static function integer(mixed $v): int
    { if (!is_int($v) || $v < 0 || $v > 9007199254740991) { throw new \InvalidArgumentException('Nonnegative safe integer required'); } return $v; }
    public static function decimal(mixed $v): string
    { if (!is_string($v) || strlen($v) > 128 || preg_match('/^(0|[1-9][0-9]*)(\\.[0-9]+)?$/D',$v) !== 1) { throw new \InvalidArgumentException('Exact nonnegative decimal string required'); } return $v; }
    public static function compare(UtcInstant $a, UtcInstant $b): int
    { return strcmp(substr($a->value,0,19).'.'.substr(substr($a->value,20,-1).'000000',0,6),substr($b->value,0,19).'.'.substr(substr($b->value,20,-1).'000000',0,6)); }
}
