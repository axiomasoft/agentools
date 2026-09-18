<?php

declare(strict_types=1);

namespace AgenTools\Tests\Ops;

use AgenTools\Error\ToolErrorCode;
use AgenTools\Ops\BaggageLimitException;
use AgenTools\Ops\BaggageRefs;
use PHPUnit\Framework\TestCase;

final class BaggageRefsTest extends TestCase
{
    /** AOB-29; baggage carries only opaque identifiers. */
    public function testItAcceptsOpaqueIdentifiers(): void
    {
        $baggage = (new BaggageRefs())->add('act-1')->add('tenant-42');

        self::assertSame(['act-1', 'tenant-42'], $baggage->refs());
    }

    /** AOB-30; substantive payloads are rejected. */
    public function testItRejectsNonOpaqueValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BaggageRefs())->add('user name with spaces');
    }

    /** AOB-31; exceeding baggage limits yields a typed budget error. */
    public function testItRejectsBaggageOverItemLimit(): void
    {
        $baggage = new BaggageRefs();
        for ($i = 0; $i < 64; ++$i) {
            $baggage = $baggage->add('id-' . $i);
        }

        try {
            $baggage->add('id-overflow');
            self::fail('Expected baggage limit failure.');
        } catch (BaggageLimitException $exception) {
            self::assertSame(ToolErrorCode::BaggageLimitExceeded->value, $exception->result->error?->code);
        }
    }
}
