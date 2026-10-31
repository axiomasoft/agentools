<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\RunCheckpoint;

/** Persist together with the checkpoint under adapter CAS. */
final readonly class RunCommandOutcome
{
    public function __construct(public RunCommand $command, public RunCheckpoint $checkpoint)
    {
        $command->runId->tenant->assertSame($checkpoint->runId->tenant);
        if ($command->runId->value !== $checkpoint->runId->value || $command->next !== $checkpoint->status
            || $command->expectedVersion + 1 !== $checkpoint->version || $command->claimEpoch !== $checkpoint->claimEpoch) {
            throw new \InvalidArgumentException('Command outcome must bind run, step, version and claim');
        }
    }
}
