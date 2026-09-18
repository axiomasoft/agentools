<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Action\ActionEvent;

/** Append-only action journal persistence. The folded state is always derived elsewhere. */
interface EventLog
{
    /**
     * Append one event. Re-appending the same `event_id` is a no-op.
     *
     * @throws \AgenTools\Action\VersionConflict when `expected_prev_seq` does not match the tail.
     * @throws \AgenTools\Action\LifecycleException when the event breaks journal integrity.
     */
    public function append(ActionEvent $event): void;

    /** @return list<ActionEvent> */
    public function readFrom(string $actionId, int $fromSeq): array;

    /** Returns `0` when the action has no events yet. */
    public function lastSeq(string $actionId): int;
}
