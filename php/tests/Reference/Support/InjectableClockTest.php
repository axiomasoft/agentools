<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Support;

use AgenTools\Reference\Support\InjectableClock;
use PHPUnit\Framework\TestCase;

final class InjectableClockTest extends TestCase
{
    /** INT-002 §2.4; injectable now for deterministic E2E. */
    public function testItAdvancesInjectedTime(): void
    {
        $clock = new InjectableClock(new \DateTimeImmutable('2026-08-05T10:00:00+00:00'));

        self::assertSame('2026-08-05T10:00:00+00:00', $clock->now()->format(DATE_ATOM));

        $clock->advance('PT5M');

        self::assertSame('2026-08-05T10:05:00+00:00', $clock->now()->format(DATE_ATOM));
    }

    public function testItSetsInjectedTime(): void
    {
        $clock = new InjectableClock();
        $clock->set(new \DateTimeImmutable('2026-09-19T06:30:00+00:00'));

        self::assertSame('2026-09-19T06:30:00+00:00', $clock->now()->format(DATE_ATOM));
    }
}
