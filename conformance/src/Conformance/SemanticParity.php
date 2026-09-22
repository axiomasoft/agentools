<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

final class SemanticParity
{
    public const FAMILIES = [
        'error_codes',
        'case_retryability',
        'states',
        'transitions',
        'envelope_versions',
        'bounds',
        'outcomes',
        'capabilities',
    ];

    private const FAMILY_SHAPES = [
        'error_codes' => ['key' => 'code', 'fields' => ['category', 'default_retryable']],
        'case_retryability' => ['key' => 'case_id', 'fields' => ['semantic_code', 'category', 'retryable']],
        'states' => ['key' => 'key', 'fields' => ['domain', 'value']],
        'transitions' => ['key' => 'transition', 'fields' => ['from', 'event', 'to']],
        'envelope_versions' => ['key' => 'envelope', 'fields' => ['version', 'prefix']],
        'bounds' => ['key' => 'bound', 'fields' => ['value']],
        'outcomes' => ['key' => 'key', 'fields' => ['domain', 'value']],
        'capabilities' => ['key' => 'capability', 'fields' => []],
    ];

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     *
     * @return array{missing: list<string>, extra: list<string>, mismatch: list<string>}
     */
    public static function compareErrorCodes(array $expected, array $actual): array
    {
        return self::compareFamily('error_codes', $expected, $actual);
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @return array<string, array{missing: list<string>, extra: list<string>, mismatch: list<string>}>
     */
    public static function compareProfiles(array $expected, array $actual): array
    {
        $result = [];
        foreach (self::FAMILIES as $family) {
            $result[$family] = self::compareFamily($family, $expected, $actual);
        }

        return $result;
    }

    /** @param array<string, array{missing: list<string>, extra: list<string>, mismatch: list<string>}> $comparison */
    public static function failed(array $comparison): bool
    {
        foreach ($comparison as $family) {
            if ($family['missing'] !== [] || $family['extra'] !== [] || $family['mismatch'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @return array{missing: list<string>, extra: list<string>, mismatch: list<string>}
     */
    private static function compareFamily(string $family, array $expected, array $actual): array
    {
        $shape = self::FAMILY_SHAPES[$family];
        $expectedRows = self::indexRows($family, $expected[$family] ?? [], $shape['key']);
        $actualRows = self::indexRows($family, $actual[$family] ?? [], $shape['key']);

        $missing = array_values(array_diff(array_keys($expectedRows), array_keys($actualRows)));
        $extra = array_values(array_diff(array_keys($actualRows), array_keys($expectedRows)));
        sort($missing);
        sort($extra);
        $missing = array_map(static fn(string $key): string => "{$family}:{$key}", $missing);
        $extra = array_map(static fn(string $key): string => "{$family}:{$key}", $extra);

        $mismatch = [];
        foreach (array_intersect(array_keys($expectedRows), array_keys($actualRows)) as $key) {
            foreach ($shape['fields'] as $field) {
                if (($expectedRows[$key][$field] ?? null) !== ($actualRows[$key][$field] ?? null)) {
                    $mismatch[] = "{$family}:{$key}.{$field}";
                }
            }
        }
        if ($family !== 'capabilities' && ($expectedRows === [] || $actualRows === [])) {
            $mismatch[] = "{$family}:__empty__";
        }
        sort($mismatch);

        return ['missing' => $missing, 'extra' => $extra, 'mismatch' => $mismatch];
    }

    /**
     * @param mixed $rows
     * @return array<string, array<string, mixed>>
     */
    private static function indexRows(string $family, mixed $rows, string $keyField): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            return [];
        }
        $indexed = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($keyField, $row) || !is_string($row[$keyField])) {
                continue;
            }
            $key = $row[$keyField];
            if (array_key_exists($key, $indexed)) {
                $key .= '#duplicate';
            }
            $indexed[$key] = $row;
        }
        ksort($indexed);

        return $indexed;
    }
}
