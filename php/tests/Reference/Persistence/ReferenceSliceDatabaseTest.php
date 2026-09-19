<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Persistence;

use AgenTools\Reference\Persistence\ReferenceSliceDatabase;
use PHPUnit\Framework\TestCase;

final class ReferenceSliceDatabaseTest extends TestCase
{
    /** INT-002 §4; migrations apply and roll back cleanly. */
    public function testMigrationsUpAndDownAreClean(): void
    {
        $database = ReferenceSliceDatabase::open(':memory:');
        self::assertSame(1, $database->currentVersion());

        $database->migrateDown();
        self::assertSame(0, $database->currentVersion());

        $database->migrateUp();
        self::assertSame(1, $database->currentVersion());
    }
}
