<?php

declare(strict_types=1);

namespace AgenTools\Tests\Schema;

use AgenTools\Schema\Canonicalizer;
use PHPUnit\Framework\TestCase;

final class CanonicalizerOracleTest extends TestCase
{
    private const ORACLE = __DIR__ . '/../../../conformance/oracles/jcs';

    public function testIndependentOracleVectorsMatchBytes(): void
    {
        $canonicalizer = new Canonicalizer();
        $inputDir = self::ORACLE . '/input';
        $files = glob($inputDir . '/*.json') ?: [];
        foreach ($files as $inputPath) {
            $name = basename($inputPath, '.json');
            $expectedPath = self::ORACLE . '/output/' . $name . '.json';
            self::assertFileExists($expectedPath, $name);
            $input = (string) file_get_contents($inputPath);
            $expected = rtrim((string) file_get_contents($expectedPath), "\n");
            $actual = $canonicalizer->canonicalizeJson($input);
            self::assertSame($expected, $actual, $name);
        }
    }
}
