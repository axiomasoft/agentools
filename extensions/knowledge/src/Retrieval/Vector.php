<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

final class Vector
{
    /** Adapter boundary: validate shape and numeric values before using a vector.
     * @param array<array-key,mixed> $vector
     */
    public static function validate(array $vector, int $dimensions): void
    {
        if ($dimensions < 1 || !array_is_list($vector) || count($vector) !== $dimensions) { throw new Failure('unavailable', 'INVALID_EMBEDDING'); }
        foreach ($vector as $value) {
            if ((!is_float($value) && !is_int($value)) || !is_finite($value)) { throw new Failure('unavailable', 'INVALID_EMBEDDING'); }
        }
        if (self::scale($vector) <= 0.0) { throw new Failure('unavailable', 'INVALID_EMBEDDING'); }
    }

    /** Stable cosine computation, including large finite magnitudes.
     * @param list<float> $left
     * @param list<float> $right
     */
    public static function cosine(array $left, array $right): float
    {
        self::validate($left, count($right)); self::validate($right, count($left));
        $lscale = self::scale($left); $rscale = self::scale($right);
        $dot = 0.0; $ln = 0.0; $rn = 0.0;
        foreach ($left as $i => $value) {
            $l = $value / $lscale; $r = $right[$i] / $rscale;
            $dot += $l * $r; $ln += $l * $l; $rn += $r * $r;
        }
        return $dot / (sqrt($ln) * sqrt($rn));
    }

    /** @param list<float> $vector */
    private static function scale(array $vector): float
    {
        return array_reduce($vector, static fn(float $scale, float $value): float => max($scale, abs($value)), 0.0);
    }
}
