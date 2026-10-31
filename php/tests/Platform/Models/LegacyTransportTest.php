<?php

declare(strict_types=1);

namespace AgenTools\Tests\Platform\Models;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/packages/agentools-core/tests/Models/bootstrap.php';

final class LegacyTransportTest extends TestCase
{
    public function testActualLegacyFixturesAndBridges(): void
    {
        $cases = \AgenToolsPlatform\Tests\Harness::loadCases(dirname(__DIR__, 4) . '/packages/agentools-core/tests/Models/legacy.php');
        foreach ($cases as $case) { $case(); }
        self::assertCount(4, $cases);
    }
}
