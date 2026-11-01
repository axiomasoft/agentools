<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Runs outside the journal transaction, after the raw exchange has been durably saved.
 * Prepare/check/store an immutable visible export only; never perform destination IO here.
 */
interface ResponsePublisher
{
    public function prepare(ExecutionContext $context, InvocationPlan $plan,
        InvocationCheckpoint $checkpoint, Ref $response): ResponsePublication;
}
