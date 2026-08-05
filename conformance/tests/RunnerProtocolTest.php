<?php

declare(strict_types=1);

namespace AgenTools\Conformance\Tests;

use AgenTools\Conformance\ProtocolViolation;
use AgenTools\Conformance\Runner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunnerProtocolTest extends TestCase
{
    public function testOracleSafeRunAndAllRunnerOwnedProbeKinds(): void
    {
        $report = (new Runner())->run($this->options('probe.php'));

        self::assertSame('1.1', $report['harness_protocol_version']);
        self::assertSame(['total' => 1, 'pass' => 1, 'fail' => 0], $report['summary']);
        self::assertSame([], $report['vectors'][0]['mismatches']);
    }

    #[DataProvider('forbiddenResultFields')]
    public function testResultOwnedProbeFactsAreProtocolViolations(string $field): void
    {
        $this->expectException(ProtocolViolation::class);
        $this->expectExceptionMessage($field);
        (new Runner())->run($this->options('result-owned.php', ['FORBIDDEN_FIELD' => $field]));
    }

    public static function forbiddenResultFields(): array
    {
        return [['tool_calls'], ['observations']];
    }

    /** @param array<string,string> $environment */
    private function options(string $adapter, array $environment = []): array
    {
        foreach ($environment as $name => $value) {
            putenv("{$name}={$value}");
        }
        return [
            'adapter' => [PHP_BINARY, __DIR__ . '/fixtures/adapters/' . $adapter],
            'vectors' => __DIR__ . '/fixtures/probe-corpus',
            'spec_rev' => '2026-08',
            'level' => null,
            'filter' => null,
            'rules' => null,
            'timeout' => 1.0,
        ];
    }
}
