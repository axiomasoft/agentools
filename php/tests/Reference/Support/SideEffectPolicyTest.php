<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Support;

use AgenTools\Reference\Support\SideEffectPolicy;
use PHPUnit\Framework\TestCase;

final class SideEffectPolicyTest extends TestCase
{
    /** INT-002 §3; only the published side-effect table is used. */
    public function testItClassifiesCoreSideEffects(): void
    {
        self::assertFalse(SideEffectPolicy::requiresPolicyHint('read'));
        self::assertFalse(SideEffectPolicy::requiresTwoPhase('read'));
        self::assertFalse(SideEffectPolicy::requiresDualControl('read'));

        self::assertFalse(SideEffectPolicy::requiresPolicyHint('draft'));
        self::assertFalse(SideEffectPolicy::requiresTwoPhase('draft'));

        self::assertTrue(SideEffectPolicy::requiresPolicyHint('write'));
        self::assertTrue(SideEffectPolicy::requiresTwoPhase('write'));
        self::assertFalse(SideEffectPolicy::requiresDualControl('write'));

        self::assertTrue(SideEffectPolicy::requiresPolicyHint('irreversible'));
        self::assertTrue(SideEffectPolicy::requiresTwoPhase('irreversible'));
        self::assertTrue(SideEffectPolicy::requiresDualControl('irreversible'));
    }
}
