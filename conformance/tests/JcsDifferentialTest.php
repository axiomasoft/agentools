<?php

declare(strict_types=1);

namespace AgenTools\Conformance\Tests;

use PHPUnit\Framework\TestCase;

final class JcsDifferentialTest extends TestCase
{
    public function testReportIsGreen(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = $root . '/conformance/bin/jcs-differential';
        exec('php ' . escapeshellarg($bin), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        $report = json_decode(
            (string) file_get_contents($root . '/conformance/reports/jcs-parity.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($report['php_rows'] as $row) {
            self::assertTrue($row['match'], $row['case_id']);
        }
        self::assertSame(0, $report['dart_exit']);
    }
}
