<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler\Tests;

use AgenToolsPlatform\Core\Common\{InstanceId, Ref, SessionId, TenantId, UtcInstant};
use AgenToolsPlatform\Scheduler\{InspectionFailure, Planner, Schedule};
use PHPUnit\Framework\TestCase;

final class CalendarTest extends TestCase
{
    private function schedule(string $time = '01:30:00', string $overlap = 'choose-first', string $gap = 'skip', string $misfire = 'catchup'): Schedule
    {
        $tenant = new TenantId('alpha');
        return new Schedule(new Ref($tenant,'daily'),1,new InstanceId($tenant,'assistant'),new SessionId($tenant,'session'),
            new Ref($tenant,'actor'),null,'scheduled.read',new Ref($tenant,'template:daily'),'America/New_York',$time,
            new UtcInstant('2026-01-01T00:00:00Z'),new UtcInstant('2027-01-01T00:00:00Z'),$overlap,$gap,$misfire,3);
    }
    public function testOverlapAndWorkerTimezone(): void
    {
        $planner = new Planner(); $before = date_default_timezone_get();
        try {
            foreach (['Pacific/Auckland','Europe/Volgograd','UTC'] as $zone) {
                date_default_timezone_set($zone);
                self::assertSame('2026-11-01T05:30:00Z',$planner->wall('2026-11-01',$this->schedule())?->value);
                self::assertSame('2026-11-01T06:30:00Z',$planner->wall('2026-11-01',$this->schedule(overlap:'choose-last'))?->value);
            }
        } finally { date_default_timezone_set($before); }
        $this->expectException(InspectionFailure::class);
        $planner->wall('2026-11-01',$this->schedule(overlap:'reject'));
    }
    public function testMissingWallTime(): void
    {
        $planner = new Planner();
        self::assertNull($planner->wall('2026-03-08',$this->schedule(time:'02:30:00')));
        self::assertSame('2026-03-09T06:30:00Z',$planner->wall('2026-03-09',$this->schedule(time:'02:30:00'))?->value);
        $this->expectException(InspectionFailure::class);
        $planner->wall('2026-03-08',$this->schedule(time:'02:30:00',gap:'reject'));
    }
    public function testBoundedMisfirePolicies(): void
    {
        $planner = new Planner(); $after = new UtcInstant('2026-01-01T00:00:00Z'); $through = new UtcInstant('2026-02-20T07:00:00Z');
        $catchup = $planner->due($this->schedule(),$after,$through);
        self::assertCount(3,$catchup); self::assertSame('2026-02-18T06:30:00Z',$catchup[0]->value);
        self::assertCount(1,$planner->due($this->schedule(misfire:'one'),$after,$through));
        self::assertSame('2026-01-01T06:30:00Z',$planner->due($this->schedule(misfire:'one'),$after,$through)[0]->value);
        self::assertSame('2026-02-20T06:30:00Z',$planner->due($this->schedule(misfire:'latest'),$after,$through)[0]->value);
        self::assertSame([],$planner->due($this->schedule(misfire:'skip'),$after,$through));
        self::assertCount(1,$planner->due($this->schedule(misfire:'skip'),$after,new UtcInstant('2026-02-20T06:31:00Z')));
        self::assertSame([],$planner->due($this->schedule(misfire:'skip'),$after,new UtcInstant('2026-02-20T06:31:00.000001Z')));
    }
    public function testInvalidPoliciesAndRange(): void
    {
        foreach (['overlap','gap','misfire'] as $policy) {
            try { $this->schedule(...[$policy=>'implicit']); self::fail('Explicit policy required'); }
            catch (\InvalidArgumentException $error) { self::assertSame('Explicit bounded schedule policies required',$error->getMessage()); }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->schedule(time:'25:90:00');
    }
}
