<?php

declare(strict_types=1);

namespace AgenTools\Conformance\Tests;

use AgenTools\Conformance\SemanticParity;
use PHPUnit\Framework\Attributes\DataProvider;
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
        ];
        foreach (SemanticParity::FAMILIES as $family) {
            $normative[$family] = $manifest[$family];
        }
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

        self::assertFalse(SemanticParity::failed(SemanticParity::compareProfiles($normative, $php)));
        self::assertFalse(SemanticParity::failed(SemanticParity::compareProfiles($normative, $dart)));
        self::assertFalse(SemanticParity::failed(SemanticParity::compareProfiles($php, $dart)));
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
        self::assertContains('error_codes:agentools.validation.mutant_extra', $result['extra']);
    }

    #[DataProvider('semanticMutants')]
    public function testSemanticMutantsAreRed(string $family, callable $mutate, string $needle): void
    {
        $base = $this->minimalProfile();
        $mutant = $base;
        $mutate($mutant);
        $result = SemanticParity::compareProfiles($base, $mutant);
        self::assertTrue(SemanticParity::failed($result));
        self::assertStringContainsString($needle, implode('|', [
            ...$result[$family]['missing'],
            ...$result[$family]['extra'],
            ...$result[$family]['mismatch'],
        ]));
    }

    public static function semanticMutants(): array
    {
        return [
            'missing code' => ['error_codes', static function (array &$p): void { $p['error_codes'] = []; }, 'error_codes:agentools.validation.x'],
            'wrong retryability' => ['case_retryability', static function (array &$p): void { $p['case_retryability'][0]['retryable'] = true; }, 'case_retryability:C-1.retryable'],
            'removed transition' => ['transitions', static function (array &$p): void { $p['transitions'] = []; }, 'transitions:ALC-T1'],
            'changed bound' => ['bounds', static function (array &$p): void { $p['bounds'][0]['value'] = 2; }, 'bounds:x.max.value'],
            'wrong outcome' => ['outcomes', static function (array &$p): void { $p['outcomes'][0]['value'] = 'FAILED'; }, 'outcomes:scenario:COMPLETED.value'],
            'unsupported capability' => ['capabilities', static function (array &$p): void { $p['capabilities'][] = ['capability' => 'mutant']; }, 'capabilities:mutant'],
            'extra SDK row' => ['states', static function (array &$p): void { $p['states'][] = ['key' => 'action:mutant', 'domain' => 'action', 'value' => 'mutant']; }, 'states:action:mutant'],
        ];
    }

    private function minimalProfile(): array
    {
        return [
            'error_codes' => [['code' => 'agentools.validation.x', 'category' => 'validation', 'default_retryable' => false]],
            'case_retryability' => [['case_id' => 'C-1', 'semantic_code' => 'agentools.validation.x', 'category' => 'validation', 'retryable' => false]],
            'states' => [['key' => 'action:created', 'domain' => 'action', 'value' => 'created']],
            'transitions' => [['transition' => 'ALC-T1', 'from' => null, 'event' => 'action_created', 'to' => 'created']],
            'envelope_versions' => [['envelope' => 'confirmation_token', 'version' => 1, 'prefix' => 'at1.']],
            'bounds' => [['bound' => 'x.max', 'value' => 1]],
            'outcomes' => [['key' => 'scenario:COMPLETED', 'domain' => 'scenario', 'value' => 'COMPLETED']],
            'capabilities' => [['capability' => 'unknown']],
        ];
    }
}
