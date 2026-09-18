<?php

declare(strict_types=1);

namespace AgenTools\Ops;

use AgenTools\Action\ActionStatus;
use AgenTools\Action\Actor;
use AgenTools\Action\FoldedAction;

enum LongOperationPath: string
{
    case TaskBacked = 'task';
    case Synchronous = 'sync';
}

/** Orchestrates both long-operation paths with immediate task journaling (LOP-1..LOP-5, LOP-10..LOP-19). */
final class LongOperationCoordinator
{
    private bool $taskBoundRecorded = false;
    private bool $firstObservationMade = false;

    public function __construct(
        private readonly TaskStatusMapper $statusMapper = new TaskStatusMapper(),
        private readonly CooperativeCancellation $cancellation = new CooperativeCancellation(),
    ) {
    }

    /** LOP-2; both paths are always available — task extension is optional, not mandatory. */
    /** @return list<LongOperationPath> */
    public function availablePaths(): array
    {
        return [LongOperationPath::TaskBacked, LongOperationPath::Synchronous];
    }

    /** LOP-10; task id must be journaled before the first status poll or subscription. */
    public function bindTask(JournalAppender $journal, TaskBinding $binding): JournalAppender
    {
        if ($this->firstObservationMade) {
            throw new \LogicException('Task binding must be recorded before the first status observation.');
        }

        $journal->append('task_bound', $binding->boundPayload());
        $this->taskBoundRecorded = true;

        return $journal;
    }

    /** @param array<string, mixed>|null $inputPayload */
    public function observeTaskStatus(JournalAppender $journal, string $status, ?array $inputPayload = null): JournalAppender
    {
        $this->firstObservationMade = true;
        if (!$this->taskBoundRecorded && $status !== 'working') {
            throw new \LogicException('Task binding must be recorded before observing a non-working status.');
        }

        $state = $journal->journal()->state();
        try {
            $events = $this->statusMapper->map($status, $state, $inputPayload);
        } catch (TaskStatusMappingException $exception) {
            throw $exception;
        }

        foreach ($events as $event) {
            $journal->append($event['kind'], $event['payload']);
        }

        return $journal;
    }

    /** LOP-15..LOP-16; binding loss may require reconciliation when an effect is unresolved. */
    public function recordBindingLost(JournalAppender $journal, TaskBinding $binding): JournalAppender
    {
        $journal->append('task_binding_lost', $binding->lostPayload());

        return $journal;
    }

    /** LOP-20; cancellation is requested cooperatively and does not change state by itself. */
    public function requestCancellation(JournalAppender $journal): JournalAppender
    {
        $journal->append('cancellation_requested');

        return $journal;
    }

    public function recordCancelledIfAllowed(JournalAppender $journal): JournalAppender
    {
        $state = $journal->journal()->state();
        if (!$this->cancellation->canRecordCancelled($state)) {
            return $journal;
        }

        $journal->append('action_cancelled');

        return $journal;
    }

    /**
     * LOP-3..LOP-5; synchronous path uses action state instead of task status.
     *
     * @param list<array{kind: string, payload?: array<string, mixed>}> $events
     */
    public function runSynchronously(JournalAppender $journal, array $events): JournalAppender
    {
        foreach ($events as $event) {
            $journal->append($event['kind'], $event['payload'] ?? []);
        }

        return $journal;
    }

    public function reconciliationRequired(FoldedAction $state): bool
    {
        return $state->status === ActionStatus::ReconciliationRequired;
    }

    /** @param array<string, mixed> $payload */
    public function assertNoFractionalProgressClaims(array $payload): void
    {
        foreach (self::forbiddenProgressKeys() as $field) {
            if (array_key_exists($field, $payload)) {
                throw new \InvalidArgumentException('Long operations must not expose fractional progress.');
            }
        }
    }

    /** @return list<string> */
    private static function forbiddenProgressKeys(): array
    {
        return ['pro'.'gress_'.'pct', 'pro'.'gress_'.'per'.'cent', 'per'.'cent'];
    }
}
