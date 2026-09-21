<?php

declare(strict_types=1);

namespace AgenTools\Conformance\Tests;

use PHPUnit\Framework\TestCase;

final class AdversarialDifferentialTest extends TestCase
{
    public function testCorpusReceiptIsGreen(): void
    {
        $root = dirname(__DIR__, 2);
        $receipt = $root . '/plans/2026.09.19-№1-AGENTOOLS-HARDENING/artifacts/P8-property-fuzz.json';
        self::assertFileExists($receipt);
        $report = json_decode((string) file_get_contents($receipt), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('green', $report['verdict']);
        self::assertSame([], $report['failed']);
    }
}
