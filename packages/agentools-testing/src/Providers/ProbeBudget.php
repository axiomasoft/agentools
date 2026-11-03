<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Testing\Providers;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Models\{Deployment,ModelRequest,ModelResponse};

/** Required host test-account owner. A test ceiling is not local billable permission. */
interface ProbeBudget
{
    public function assertCurrent(ModelRequest $request,Deployment $deployment): void;
    /** Atomically persist/reserve/dedup the Invocation+payload before IO; rejects previously attempted/unknown keys and hash conflicts. */
    public function reserve(ModelRequest $request,Deployment $deployment,int $ceilingMicros): Ref;
    /** Durable accounting; response=null retains unknown exposure, never automatic release or zero expense. */
    public function record(Ref $reservation,ModelRequest $request,?ModelResponse $response): void;
}
