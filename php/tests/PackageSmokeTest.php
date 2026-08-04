<?php

declare(strict_types=1);

namespace AgenTools\Tests;

use AgenTools\Action\Actor;
use PHPUnit\Framework\TestCase;

final class PackageSmokeTest extends TestCase
{
    public function testComposerAutoloadExposesSdkClasses(): void
    {
        self::assertTrue(class_exists(Actor::class));
    }
}
