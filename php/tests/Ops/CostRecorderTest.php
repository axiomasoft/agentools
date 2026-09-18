<?php

declare(strict_types=1);

namespace AgenTools\Tests\Ops;

use AgenTools\Ops\CostRecorder;
use AgenTools\Ops\TraceCorrelation;
use PHPUnit\Framework\TestCase;

final class CostRecorderTest extends TestCase
{
    /** AOB-23, AOB-24; cost is computed from token usage and a versioned price table. */
    public function testItCalculatesCostFromTokenUsage(): void
    {
        $recorder = new CostRecorder();
        $amount = $recorder->calculateAmountMinor(100, 50, ['input_minor' => 2, 'output_minor' => 3]);

        self::assertSame(350, $amount);
    }

    /** AOB-20, AOB-21, AOB-22; long gates end the prepare span and resume via link. */
    public function testTraceCorrelationEndsPrepareSpanAndLinksCommit(): void
    {
        $trace = new TraceCorrelation(
            '0123456789abcdef0123456789abcdef',
            '0123456789abcdef',
            'fedcba9876543210fedcba9876543210',
            'fedcba9876543210',
        );

        $requested = $trace->confirmationRequestedPayload();
        $commit = $trace->commitResumePayload();

        self::assertIsArray($requested['trace']);
        self::assertSame('0123456789abcdef0123456789abcdef', $requested['trace']['trace_id']);
        self::assertIsArray($commit['trace']);
        self::assertArrayHasKey('link', $commit['trace']);
    }
}
