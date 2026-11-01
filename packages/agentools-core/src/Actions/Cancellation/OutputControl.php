<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Cancellation;

/** Host checks current tenant/session permissions and output fences, stops by stable key/hash, and attests actual acknowledgements.
 * A repeated key/hash must recover its original stop receipt before checking a now-advanced output version.
 */
interface OutputControl
{
    public function stop(Operation $operation): InterruptedOutput;
}
