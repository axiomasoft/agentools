<?php

declare(strict_types=1);

namespace AgenTools\Ops;

/** Opaque task binding record aligned with schema/task-binding.json (LOP-10, LOP-27). */
final readonly class TaskBinding
{
    public function __construct(
        public string $actionId,
        public string $taskId,
        public ?string $channel = null,
        public ?string $observedStatus = null,
        public ?string $lostReason = null,
    ) {
        if ($actionId === '' || $taskId === '') {
            throw new \InvalidArgumentException('Action and task identifiers are required.');
        }
        if ($lostReason !== null && !in_array($lostReason, ['expired', 'unknown'], true)) {
            throw new \InvalidArgumentException('Task binding loss reason must be expired or unknown.');
        }
    }

    /** @return array<string, mixed> */
    public function boundPayload(): array
    {
        $payload = ['task_id' => $this->taskId];
        if ($this->channel !== null) {
            $payload['channel'] = $this->channel;
        }
        if ($this->observedStatus !== null) {
            $payload['observed_status'] = $this->observedStatus;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function lostPayload(): array
    {
        if ($this->lostReason === null) {
            throw new \LogicException('A lost binding requires a reason.');
        }

        $payload = ['reason' => $this->lostReason];
        if ($this->taskId !== '') {
            $payload['task_id'] = $this->taskId;
        }

        return $payload;
    }
}
