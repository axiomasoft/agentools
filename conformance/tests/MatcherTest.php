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

    public function testToolCallMatchesFixtureArgsSubsetAgainstActualArgs(): void
    {
        self::assertSame([], Matcher::vector(
            ['tool_calls' => [[
                'tool' => 'demo_probe',
                'phase' => 'commit',
                'args_subset' => ['value' => 'x'],
            ]]],
            [],
            null,
            [['tool' => 'demo_probe', 'phase' => 'commit', 'args' => ['value' => 'x', 'token' => 't']]],
            null,
        ));
    }

    public function testObservationsAreMatchedIndependentlyAndPartially(): void
    {
        self::assertSame([], Matcher::vector(
            ['observations' => [[
                'kind' => 'tool_boundary',
                'probe_id' => 'tool-spy',
                'args_subset' => ['value' => 'x'],
            ]]],
            [],
            null,
            [],
            null,
            [[
                'kind' => 'tool_boundary',
                'probe_id' => 'tool-spy',
                'tool' => 'demo_probe',
                'args' => ['value' => 'x', 'extra' => true],
            ]],
        ));
    }

    public function testMissingObservationIsRed(): void
    {
        $mismatches = Matcher::vector(
            ['observations' => [['kind' => 'audit_sink', 'probe_id' => 'audit-spy']]],
            [],
            null,
            [],
            null,
            [],
        );
        self::assertStringContainsString('не снято harness', $mismatches[0]);
    }
}
