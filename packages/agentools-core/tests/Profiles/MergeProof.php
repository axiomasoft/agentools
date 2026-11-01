<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Profiles\Merge;

use AgenToolsPlatform\Core\Profiles\Composition\{CompiledComposition, ProfileConflict, Values};

final class Proof
{
    public static function compiled(CompiledComposition|ProfileConflict $result): CompiledComposition
    {
        if (!$result instanceof CompiledComposition) { throw new \RuntimeException('Unexpected conflict: '.$result->code); }
        return $result;
    }
    public static function conflict(CompiledComposition|ProfileConflict $result): ProfileConflict
    {
        if (!$result instanceof ProfileConflict) { throw new \RuntimeException('Expected field conflict'); }
        return $result;
    }
    /**
     * @return array<string, mixed>
     */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) { throw new \RuntimeException('Object expected'); }
        foreach ($value as $key => $_) { if (!is_string($key)) { throw new \RuntimeException('Object names expected'); } }
        return $value;
    }
    /**
     * @return array<string, mixed>
     */
    public static function restrictions(CompiledComposition $result): array { return self::object(json_decode($result->compiled->restrictionsJson, true, 64, JSON_THROW_ON_ERROR)); }
    /**
     * @return array<string, mixed>
     */
    public static function effective(CompiledComposition $result): array { return self::object(json_decode($result->compiled->effectiveJson, true, 64, JSON_THROW_ON_ERROR)); }
    /**
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        $wire = file_get_contents(dirname(__DIR__, 4).'/fixtures/platform/v1/merge/'.$name);
        if ($wire === false) { throw new \RuntimeException('Required merge fixture missing'); }
        return self::object(json_decode($wire, true, 64, JSON_THROW_ON_ERROR));
    }
    /**
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        if (!is_array($value)) { throw new \RuntimeException('Explicit list expected'); }
        return Values::set($value);
    }
    public static function count(mixed $value): int
    {
        if (!is_array($value)) { throw new \RuntimeException('List expected'); }
        return count($value);
    }
}
