<?php

declare(strict_types=1);

namespace AgenTools\Tests\Adversarial;

use PHPUnit\Framework\TestCase;

final class PackageBoundaryKillTest extends TestCase
{
    public function testProductionAutoloadDoesNotIncludeTestkitOrReference(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        $autoload = $composer['autoload'] ?? null;
        self::assertIsArray($autoload);
        $psr4 = $autoload['psr-4'] ?? null;
        self::assertIsArray($psr4);
        self::assertArrayNotHasKey('AgenTools\\TestKit\\', $psr4);
        self::assertArrayNotHasKey('AgenTools\\Reference\\', $psr4);
        self::assertSame(['AgenTools\\' => 'src/'], $psr4);
    }
}
