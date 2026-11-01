<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

use AgenToolsPlatform\Core\Actions\ActionSubmission;
use AgenToolsPlatform\Core\Runtime\{AdvanceLease, InvocationPlan};

/** The host compiles exactly one bounded exchange or one direct Action. Never a callback. */
final readonly class Work
{
    private function __construct(public ?InvocationPlan $plan, public ?AdvanceLease $lease,
        public ?ActionSubmission $action) {}

    public static function invocation(InvocationPlan $plan, AdvanceLease $lease): self
    {
        $plan->runId->assertSame($lease->runId);
        return new self($plan, $lease, null);
    }

    public static function action(ActionSubmission $submission): self
    {
        return new self(null, null, $submission);
    }
}
