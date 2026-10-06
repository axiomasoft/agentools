<?php

declare(strict_types=1);

namespace AgenTools\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * PostgreSQL-backed tests skip locally when no test database is reachable. With
 * AGENTOOLS_TEST_PG_REQUIRED=1 (set by CI) a missing database fails the test instead, so the
 * durable adapters cannot pass a pipeline by being silently skipped.
 */
final class PostgresRequirement
{
    public const REQUIRED_ENV = 'AGENTOOLS_TEST_PG_REQUIRED';

    public static function unavailable(): never
    {
        if (getenv(self::REQUIRED_ENV) === '1') {
            TestCase::fail(sprintf('PostgreSQL test database is required (%s=1) but not available.', self::REQUIRED_ENV));
        }
        TestCase::markTestSkipped('PostgreSQL test database is not available.');
    }
}
