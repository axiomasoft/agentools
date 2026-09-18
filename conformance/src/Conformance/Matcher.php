<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

/**
 * Сопоставляет наблюдаемое поведение с намеренно неполными ожиданиями корпуса.
 *
 * Векторы проверяют только существенные свойства события: дополнительные поля и
 * события разрешены, чтобы расширение SDK не превращалось в ложную регрессию.
 */
final class Matcher
{
    /** @return list<string> */
    public static function vector(
        array $expect,
        array $actualEvents,
        ?array $finalState,
        array $toolCalls,
        ?array $error,
        array $observations = [],
    ): array
    {
        $mismatches = self::events($expect['events'] ?? [], $actualEvents);

        if (array_key_exists('final_state', $expect)
            && (($finalState['final_state'] ?? null) !== $expect['final_state'])) {
            $mismatches[] = sprintf('final_state: ожидалось %s, получено %s',
                self::print($expect['final_state']), self::print($finalState['final_state'] ?? null));
        }

        foreach ($expect['tool_calls'] ?? [] as $index => $expected) {
            $found = false;
            foreach ($toolCalls as $actual) {
                if (self::toolCall($expected, $actual)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $mismatches[] = sprintf('tool_calls[%d]: ожидаемый вызов не наблюдался: %s', $index, self::print($expected));
            }
        }

        if (isset($expect['error']) && !self::subset($expect['error'], $error ?? [])) {
            $mismatches[] = sprintf('error: ожидалось %s, получено %s', self::print($expect['error']), self::print($error));
        }

        foreach ($expect['observations'] ?? [] as $index => $expected) {
            $found = false;
            foreach ($observations as $actual) {
                if (self::observation($expected, $actual)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $mismatches[] = sprintf('observations[%d]: ожидаемое наблюдение не снято harness: %s', $index, self::print($expected));
            }
        }

        return $mismatches;
    }

    private static function observation(array $expected, array $actual): bool
    {
        if (($expected['kind'] ?? null) === 'tool_boundary') {
            $argsSubset = $expected['args_subset'] ?? null;
            unset($expected['args_subset']);
            return self::subset($expected, $actual)
                && ($argsSubset === null || self::subset($argsSubset, $actual['args'] ?? []));
        }
        return self::subset($expected, $actual);
    }

    /**
     * `args_subset` — язык ожидания корпуса, а фактический вызов несёт полные
     * `args`: иначе matcher требовал бы от SUT дублировать тестовый DSL.
     */
    private static function toolCall(array $expected, array $actual): bool
    {
        $argsSubset = $expected['args_subset'] ?? null;
        unset($expected['args_subset']);
        return self::subset($expected, $actual)
            && ($argsSubset === null || self::subset($argsSubset, $actual['args'] ?? []));
    }

    /** @return list<string> */
    public static function events(array $expected, array $actual): array
    {
        $position = 0;
        foreach ($expected as $expectedIndex => $expectedEvent) {
            $matched = false;
            for ($i = $position, $count = count($actual); $i < $count; $i++) {
                if (self::subset($expectedEvent, $actual[$i])) {
                    $matched = true;
                    $position = $i + 1;
                    break;
                }
            }
            if (!$matched) {
                return [sprintf('events[%d]: ожидаемое событие не найдено в правильном порядке: %s', $expectedIndex, self::print($expectedEvent))];
            }
        }

        return [];
    }

    public static function subset(mixed $expected, mixed $actual): bool
    {
        if (!is_array($expected)) {
            return $expected === $actual;
        }
        if (!is_array($actual)) {
            return false;
        }

        if (array_is_list($expected)) {
            if (!array_is_list($actual) || count($expected) !== count($actual)) {
                return false;
            }
            foreach ($expected as $index => $value) {
                if (!self::subset($value, $actual[$index])) {
                    return false;
                }
            }
            return true;
        }

        foreach ($expected as $key => $value) {
            if (str_starts_with((string) $key, '!')) {
                if (array_key_exists(substr((string) $key, 1), $actual)) {
                    return false;
                }
                continue;
            }
            if (!array_key_exists($key, $actual) || !self::subset($value, $actual[$key])) {
                return false;
            }
        }
        return true;
    }

    private static function print(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
