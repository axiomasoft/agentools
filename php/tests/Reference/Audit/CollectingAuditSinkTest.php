<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Audit;

use AgenTools\Reference\Audit\CollectingAuditSink;
use AgenTools\Reference\Persistence\ReferenceSliceDatabase;
use PHPUnit\Framework\TestCase;

final class CollectingAuditSinkTest extends TestCase
{
    /** INT-002 §I4; audit export is tenant-scoped in SQLite projection. */
    public function testCrossTenantAuditProjectionIsolation(): void
    {
        $database = ReferenceSliceDatabase::open(':memory:');
        $sinkA = CollectingAuditSink::forDatabase($database, 'tenant-a');
        $sinkB = CollectingAuditSink::forDatabase($database, 'tenant-b');
        $sinkA->export(['kind' => 'audit.policy_evaluated', 'tenant' => 'tenant-a']);
        $sinkB->export(['kind' => 'audit.policy_evaluated', 'tenant' => 'tenant-b']);

        self::assertCount(1, $sinkA->collected());
        self::assertCount(1, $sinkB->collected());
        self::assertSame('tenant-a', $sinkA->collected()[0]['tenant']);
        self::assertSame('tenant-b', $sinkB->collected()[0]['tenant']);
        self::assertCount(1, $sinkA->loadForTenant('tenant-a'));
        self::assertCount(1, $sinkB->loadForTenant('tenant-b'));
        self::assertNotContains('tenant-b', array_column($sinkA->loadForTenant('tenant-a'), 'tenant'));
    }
}
