<?php

declare(strict_types=1);

namespace AgenTools\Conformance\Tests;

use AgenTools\Conformance\Matcher;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase
{
    public function testObjectSubsetPermitsAdditionalFields(): void
    {
        self::assertTrue(Matcher::subset(['kind' => 'created', 'payload' => ['id' => 'a']], [
            'kind' => 'created', 'payload' => ['id' => 'a', 'extra' => true], 'seq' => 1,
        ]));
    }

    public function testEventOrderIsSignificant(): void
    {
        self::assertNotSame([], Matcher::events([['kind' => 'a'], ['kind' => 'b']], [['kind' => 'b'], ['kind' => 'a']]));
    }

    public function testBangFieldRequiresAbsence(): void
    {
        self::assertTrue(Matcher::subset(['kind' => 'a', '!prev_hash' => null], ['kind' => 'a']));
        self::assertFalse(Matcher::subset(['kind' => 'a', '!prev_hash' => null], ['kind' => 'a', 'prev_hash' => 'x']));
    }

    public function testMissingExpectedEventIsRed(): void
    {
        $mismatches = Matcher::events([['kind' => 'expected']], [['kind' => 'other']]);
        self::assertNotSame([], $mismatches);
        self::assertStringContainsString('не найдено', $mismatches[0]);
    }

    public function testUnexpectedEventCannotSatisfyExpectation(): void
    {
        self::assertNotSame([], Matcher::events([['kind' => 'needed']], [['kind' => 'extra']]));
    }
}
