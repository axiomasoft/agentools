<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

final class SemanticParity
{
    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     *
     * @return array{missing: list<string>, extra: list<string>, mismatch: list<string>}
     */
    public static function compareErrorCodes(array $expected, array $actual): array
    {
        $expectedByCode = [];
        foreach ($expected['error_codes'] as $row) {
            $expectedByCode[$row['code']] = $row;
        }
        $actualByCode = [];
        foreach ($actual['error_codes'] as $row) {
            $actualByCode[$row['code']] = $row;
        }

        $missing = array_values(array_diff(array_keys($expectedByCode), array_keys($actualByCode)));
        $extra = array_values(array_diff(array_keys($actualByCode), array_keys($expectedByCode)));
        sort($missing);
        sort($extra);

        $mismatch = [];
        foreach (array_intersect(array_keys($expectedByCode), array_keys($actualByCode)) as $code) {
            foreach (['category', 'default_retryable'] as $field) {
                if (($expectedByCode[$code][$field] ?? null) !== ($actualByCode[$code][$field] ?? null)) {
                    $mismatch[] = "{$code}.{$field}";
                }
            }
        }
        sort($mismatch);

        return ['missing' => $missing, 'extra' => $extra, 'mismatch' => $mismatch];
    }
}
