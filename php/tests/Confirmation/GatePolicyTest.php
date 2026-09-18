<?php

declare(strict_types=1);

namespace AgenTools\Tests\Confirmation;

use AgenTools\Confirmation\GatePolicy;
use AgenTools\Confirmation\PolicyVerdict;
use AgenTools\Tool\ActionProfile;
use PHPUnit\Framework\TestCase;

final class GatePolicyTest extends TestCase
{
    /** CNF-2, CNF-4, CNF-6, CNF-7; deny dominates every scope and policy cannot weaken write. */
    public function testItUsesTheClosedPrecedenceOverTheEffectBaseline(): void
    {
        $policy = new GatePolicy();

        self::assertSame(PolicyVerdict::Ask, $policy->decide(new ActionProfile('write')));
        self::assertSame(PolicyVerdict::Ask, $policy->decide(new ActionProfile('write'), [PolicyVerdict::Allow]));
        self::assertSame(PolicyVerdict::Deny, $policy->decide(new ActionProfile('read'), [PolicyVerdict::Ask, PolicyVerdict::Deny]));
    }
}
