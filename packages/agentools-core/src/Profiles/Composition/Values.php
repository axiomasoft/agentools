<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

/** Bounded, case-sensitive sets: null/unknown never means unrestricted. */
final class Values
{
    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    public static function set(array $values): array
    {
        if (!array_is_list($values) || count($values) > 256) { throw new \InvalidArgumentException('Bounded explicit list required'); }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,127}$/D', $value) !== 1) {
                throw new \InvalidArgumentException('Bounded opaque policy identifier required');
            }
            $result[] = $value;
        }
        $result = array_values(array_unique($result)); sort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    public static function intersect(array $a, array $b): array { return array_values(array_intersect($a, $b)); }
    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    public static function union(array $a, array $b): array
    {
        $values = array_values(array_unique(array_merge($a, $b))); sort($values, SORT_STRING); return $values;
    }
    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    public static function subtract(array $a, array $b): array { return array_values(array_diff($a, $b)); }
}
