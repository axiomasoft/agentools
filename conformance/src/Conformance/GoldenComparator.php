<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

/** Два независимых сигнала: семантика JSON, затем байты сериализации. */
final class GoldenComparator
{
    /** @return list<string> */
    public static function compare(string $canonical, string $golden, string $canonicalGolden): array
    {
        if (str_starts_with($golden, 'sha-256:')) {
            return $canonical === $golden && $canonicalGolden === $golden ? [] : ['структурное расхождение: idempotency golden не совпал'];
        }
        try {
            $left = json_decode($canonical, true, 512, JSON_THROW_ON_ERROR);
            $right = json_decode($golden, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return ['структурное расхождение: невалидный JSON ' . $error->getMessage()];
        }
        if ($left !== $right) {
            return ['структурное расхождение: канонический JSON не равен golden'];
        }
        $signals = [];
        if ($canonical !== $golden) {
            $signals[] = 'дрейф сериализации: структура равна, байты отличаются';
        }
        if ($canonicalGolden !== $golden) {
            $signals[] = 'дрейф сериализации: повторная канонизация golden изменила байты';
        }
        return $signals;
    }
}
