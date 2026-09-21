<?php

declare(strict_types=1);

namespace AgenTools\Conformance\Tests;

use AgenTools\Conformance\SemanticParity;
use PHPUnit\Framework\TestCase;

final class SemanticParityTest extends TestCase
{
    public function testNormativeManifestMatchesSdkProfiles(): void
    {
        $root = dirname(__DIR__, 2);

        $manifest = json_decode(
            (string) file_get_contents($root . '/spec/revisions/2026-09/semantic-manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $normative = [
            'schema_version' => 'semantic-profile/v1',
            'spec_revision' => '2026-09',
            'error_codes' => $manifest['error_codes'],
        ];
        $phpJson = shell_exec(
            'php ' . escapeshellarg($root . '/php/conformance/semantic_profile.php')
        );
        self::assertIsString($phpJson);
        $php = json_decode($phpJson, true, flags: JSON_THROW_ON_ERROR);
        $dartJson = shell_exec(
            'dart run ' . escapeshellarg($root . '/dart/agentools/conformance/semantic_profile.dart')
        );
        self::assertIsString($dartJson);
        $dart = json_decode($dartJson, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['missing' => [], 'extra' => [], 'mismatch' => []], SemanticParity::compareErrorCodes($normative, $php));
        self::assertSame(['missing' => [], 'extra' => [], 'mismatch' => []], SemanticParity::compareErrorCodes($normative, $dart));
        self::assertSame(['missing' => [], 'extra' => [], 'mismatch' => []], SemanticParity::compareErrorCodes($php, $dart));
    }

    public function testMutantExtraCodeIsRed(): void
    {
        $base = [
            'error_codes' => [
                ['code' => 'agentools.validation.idempotency_key_missing', 'category' => 'validation', 'default_retryable' => false],
            ],
        ];
        $mutant = $base;
        $mutant['error_codes'][] = ['code' => 'agentools.validation.mutant_extra', 'category' => 'validation', 'default_retryable' => false];
        $result = SemanticParity::compareErrorCodes($base, $mutant);
        self::assertContains('agentools.validation.mutant_extra', $result['extra']);
    }
}
