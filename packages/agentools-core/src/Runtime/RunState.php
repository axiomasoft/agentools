<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, RunCheckpoint};

/** Pure immutable reducer input/output. No delivery state, lock, worker or IO. */
final readonly class RunState
{
    /** @param array<string, RunCommandOutcome> $commands */
    public function __construct(public RunCheckpoint $checkpoint, public array $commands = [])
    {
        foreach ($commands as $key => $outcome) {
            $checkpoint->runId->tenant->assertSame($outcome->command->runId->tenant);
            if ($key !== 'command:' . $outcome->command->commandId->value || $checkpoint->runId->value !== $outcome->command->runId->value
                || $outcome->checkpoint->version > $checkpoint->version) {
                throw new \InvalidArgumentException('Invalid saved command binding');
            }
        }
    }

    public function apply(RunCommand $command): self
    {
        $this->checkpoint->runId->tenant->assertSame($command->runId->tenant);
        if ($command->runId->value !== $this->checkpoint->runId->value) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict);
        }
        $saved = $this->commands['command:' . $command->commandId->value] ?? null;
        if ($saved !== null) {
            $saved->command->hash->assertSame($command->hash);
            return $this;
        }
        if ($command->next === \AgenToolsPlatform\Core\Common\RunStatus::Cancelled && $command->cancellationDecision === null) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict);
        }
        $checkpoint = $this->checkpoint->transition($command->next, $command->expectedVersion, $command->claimEpoch);
        return new \AgenToolsPlatform\Core\Runtime\RunState($checkpoint, $this->commands + [
            'command:' . $command->commandId->value => new \AgenToolsPlatform\Core\Runtime\RunCommandOutcome($command, $checkpoint),
        ]);
    }

    /** A duplicate returns its original outcome even if the current run has progressed. */
    public function outcome(RunCommand $command): RunCommandOutcome
    {
        $state = $this->apply($command);
        return $state->commands['command:' . $command->commandId->value];
    }
}
