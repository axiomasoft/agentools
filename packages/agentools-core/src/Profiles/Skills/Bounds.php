<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

final class Bounds
{
    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    public static function names(array $values, int $limit = 16): array
    {
        if (!array_is_list($values) || count($values) > $limit) { throw new \InvalidArgumentException('Bounded metadata list required'); }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/^[a-z][a-z0-9._:-]{0,63}$/D', $value) !== 1) { throw new \InvalidArgumentException('Opaque metadata names, not inline content, required'); }
            $result[] = $value;
        }
        $result = array_values(array_unique($result)); sort($result, SORT_STRING); return $result;
    }
    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    public static function refs(array $values): array
    {
        if (!array_is_list($values) || count($values) > 4 || $values === []) { throw new \InvalidArgumentException('Bounded explicit evidence references required'); }
        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,255}$/D', $value) !== 1) { throw new \InvalidArgumentException('Evidence reference required'); }
        }
        $values = array_values(array_unique($values)); sort($values, SORT_STRING); return $values;
    }
}
