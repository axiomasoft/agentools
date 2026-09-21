<?php

declare(strict_types=1);

/**
 * Assembles semantic-profile/v1 from production PHP constants (manual DTO gate).
 */
function agentools_semantic_profile(): array
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $categoryDefaultRetryable = [
        'validation' => false,
        'permission' => false,
        'not_found' => false,
        'precondition' => false,
        'conflict' => true,
        'unavailable' => true,
        'budget' => false,
        'internal' => false,
    ];

    $rows = [];
    foreach (\AgenTools\Error\ToolErrorCode::cases() as $case) {
        $code = $case->value;
        $parts = explode('.', $code, 3);
        $category = $parts[1] ?? 'internal';
        $rows[] = [
            'code' => $code,
            'category' => $category,
            'default_retryable' => $categoryDefaultRetryable[$category] ?? false,
            'source' => 'php/src/Error/ToolErrorCode.php',
        ];
    }
    usort($rows, static fn(array $a, array $b): int => $a['code'] <=> $b['code']);

    return [
        'schema_version' => 'semantic-profile/v1',
        'spec_revision' => '2026-09',
        'implementation' => 'php',
        'error_codes' => $rows,
        'canonicalization_profile' => 'RFC8785',
    ];
}

if (PHP_SAPI === 'cli' && basename((string) ($argv[0] ?? '')) === 'semantic_profile.php') {
    echo json_encode(agentools_semantic_profile(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
