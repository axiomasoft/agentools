<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Tool\ActionProfile;

/** Policy-only layer: it decides whether a gate is required, never handles tokens. */
final class GatePolicy
{
    /** @param list<PolicyVerdict> $applicable */
    public function decide(ActionProfile $profile, array $applicable = []): PolicyVerdict
    {
        $baseline = $profile->requiresConfirmation() ? PolicyVerdict::Ask : PolicyVerdict::Allow;
        $verdicts = [...$applicable, $baseline];
        if (in_array(PolicyVerdict::Deny, $verdicts, true)) {
            return PolicyVerdict::Deny;
        }
        if (in_array(PolicyVerdict::Ask, $verdicts, true)) {
            return PolicyVerdict::Ask;
        }
        return PolicyVerdict::Allow;
    }
}
