<?php

declare(strict_types=1);

namespace AgenTools\Action;

/**
 * Pure lifecycle fold. Every validation is performed in ALC-16 order.
 */
final class ActionReducer
{
    /** @var array<string, true> */
    private const KNOWN_KINDS = [
        'action_created' => true, 'action_started' => true, 'input_recorded' => true,
        'epoch_started' => true, 'confirmation_requested' => true, 'confirmation_resolved' => true,
        'effect_requested' => true, 'effect_confirmed' => true, 'effect_failed' => true,
        'action_completed' => true, 'action_failed' => true, 'task_bound' => true,
        'task_binding_lost' => true, 'cancellation_requested' => true, 'action_cancelled' => true,
    ];

    public function __construct(
        private readonly string $specRevision = '2026-08',
        private readonly ?JournalHashChain $hashChain = null,
        private readonly EventUpcasterChain $upcasters = new EventUpcasterChain(),
    ) {
    }

    /** @param list<ActionEvent> $events */
    public function fold(array $events): FoldedAction
    {
        $state = new FoldedAction();
        foreach ($events as $event) {
            $state = $this->apply($state, $this->upcasters->upcast($event));
        }

        return $state;
    }

    public function apply(FoldedAction $state, ActionEvent $event): FoldedAction
    {
        // ALC-16: terminality, sequence, version, epoch, chain, transition.
        if ($state->status?->isTerminal()) {
            throw new LifecycleException('A terminal action cannot accept another event.');
        }
        if ($event->seq !== $state->seq + 1) {
            throw new LifecycleException('Action journal sequence has a gap.');
        }
        if ($event->expectedPrevSeq !== $state->seq) {
            throw new VersionConflict($event->expectedPrevSeq, $state->seq);
        }
        if ($event->specRevision !== $this->specRevision) {
            throw new LifecycleException('The event was written by another specification revision.');
        }
        if ($state->actionId !== null && $event->actionId !== $state->actionId) {
            throw new LifecycleException('All records in an action journal must have the same action id.');
        }
        if ($this->isEffectEvent($event->kind) && ($event->epoch === null || $event->epoch < $state->epoch)) {
            throw new LifecycleException('An effect event was written by a stale executor epoch.');
        }
        if ($this->hashChain !== null && $event->previousHash !== $state->previousHash) {
            throw new JournalIntegrityException('The event previous hash does not match the journal chain.');
        }

        // Unknown kinds occupy their sequence but deliberately do not alter the projection.
        if (!array_key_exists($event->kind, self::KNOWN_KINDS)) {
            return new FoldedAction($event->actionId, $state->status, $event->seq, $state->epoch, $state->inputs, $state->effects, $state->cancellationRequested, $this->hash($event));
        }

        $status = $this->nextStatus($state, $event);
        $inputs = $state->inputs;
        $effects = $state->effects;
        $epoch = $state->epoch;
        $cancelled = $state->cancellationRequested;

        if ($event->kind === 'epoch_started') {
            if ($event->epoch === null || $event->epoch <= $state->epoch) {
                throw new LifecycleException('Executor epoch must increase monotonically.');
            }
            $epoch = $event->epoch;
        }
        if ($event->kind === 'input_recorded') {
            $name = $this->requiredString($event, 'name');
            $inputs[$name] = $event->payload['value'] ?? null;
        }
        if ($event->kind === 'effect_requested') {
            $effectId = $this->requiredString($event, 'effect_id');
            $eventEpoch = $this->requiredEpoch($event);
            if (array_key_exists($effectId, $effects)) {
                throw new LifecycleException('An effect can only be requested once.');
            }
            $effects[$effectId] = new EffectRecord(
                effectId: $effectId,
                status: EffectStatus::Requested,
                epoch: $eventEpoch,
                requestedSeq: $event->seq,
                toolName: $this->optionalString($event, 'tool_name'),
                idempotencyKey: $this->optionalString($event, 'idempotency_key'),
            );
        }
        if ($event->kind === 'effect_confirmed' || $event->kind === 'effect_failed') {
            $effectId = $this->requiredString($event, 'effect_id');
            $eventEpoch = $this->requiredEpoch($event);
            $effect = $effects[$effectId] ?? null;
            if ($effect === null || $effect->status !== EffectStatus::Requested) {
                throw new LifecycleException('An effect outcome requires one unresolved effect request.');
            }
            if ($eventEpoch !== $state->epoch) {
                throw new LifecycleException('An effect outcome must be written by the current executor epoch.');
            }
            $effects[$effectId] = $effect->resolve(
                $event->kind === 'effect_confirmed' ? EffectStatus::Confirmed : EffectStatus::Failed,
                $event->seq,
                $eventEpoch,
                $this->optionalString($event, 'external_ref'),
            );
        }
        if ($event->kind === 'cancellation_requested') {
            $cancelled = true;
        }

        return new FoldedAction($event->actionId, $status, $event->seq, $epoch, $inputs, $effects, $cancelled, $this->hash($event));
    }

    private function nextStatus(FoldedAction $state, ActionEvent $event): ActionStatus
    {
        $from = $state->status;
        $kind = $event->kind;
        if ($kind === 'action_created' && $from === null && $event->seq === 1 && $event->expectedPrevSeq === 0) {
            return ActionStatus::Created;
        }
        if ($from === null) {
            throw new LifecycleException('The first known event must create the action.');
        }

        return match ($from) {
            ActionStatus::Created => match ($kind) {
                'input_recorded', 'epoch_started', 'cancellation_requested' => $from,
                'action_started' => ActionStatus::Running,
                'action_cancelled' => $this->cancel($state),
                default => $this->invalidTransition($from, $kind),
            },
            ActionStatus::Running => match ($kind) {
                'input_recorded', 'epoch_started', 'task_bound', 'task_binding_lost', 'cancellation_requested' => $from,
                'confirmation_requested' => $this->confirmationRequested($event),
                'effect_requested' => ActionStatus::Applying,
                'action_completed' => ActionStatus::Completed,
                'action_failed' => ActionStatus::Failed,
                'action_cancelled' => $this->cancel($state),
                default => $this->invalidTransition($from, $kind),
            },
            ActionStatus::AwaitingConfirmation => match ($kind) {
                'confirmation_requested', 'epoch_started', 'cancellation_requested' => $from,
                'confirmation_resolved' => $this->confirmationResolved($event),
                'action_failed' => ActionStatus::Failed,
                'action_cancelled' => $this->cancel($state),
                default => $this->invalidTransition($from, $kind),
            },
            ActionStatus::Applying => match ($kind) {
                'effect_confirmed', 'effect_failed' => ActionStatus::Running,
                'effect_requested', 'epoch_started', 'task_bound', 'cancellation_requested' => $from,
                'task_binding_lost' => $state->hasUnresolvedEffect() ? ActionStatus::ReconciliationRequired : $this->invalidTransition($from, $kind),
                'action_failed' => ActionStatus::Failed,
                default => $this->invalidTransition($from, $kind),
            },
            ActionStatus::ReconciliationRequired => match ($kind) {
                'effect_confirmed', 'effect_failed' => ActionStatus::Running,
                'epoch_started', 'cancellation_requested' => $from,
                'action_failed' => ActionStatus::Failed,
                default => $this->invalidTransition($from, $kind),
            },
            default => throw new LifecycleException('A terminal action cannot transition.'),
        };
    }

    private function confirmationRequested(ActionEvent $event): ActionStatus
    {
        $this->requiredString($event, 'deadline');
        return ActionStatus::AwaitingConfirmation;
    }

    private function confirmationResolved(ActionEvent $event): ActionStatus
    {
        return match ($this->requiredString($event, 'decision')) {
            'approve' => ActionStatus::Running,
            'reject', 'revoke' => ActionStatus::Rejected,
            'expire' => ActionStatus::Expired,
            default => throw new LifecycleException('The confirmation decision is not in the transition table.'),
        };
    }

    private function cancel(FoldedAction $state): ActionStatus
    {
        if (!$state->cancellationRequested || $state->hasUnresolvedEffect()) {
            throw new LifecycleException('The action cannot be cancelled in its current state.');
        }
        return ActionStatus::Cancelled;
    }

    private function invalidTransition(ActionStatus $from, string $kind): never
    {
        throw new LifecycleException("Event {$kind} is not valid from {$from->value}.");
    }

    private function isEffectEvent(string $kind): bool
    {
        return in_array($kind, ['effect_requested', 'effect_confirmed', 'effect_failed'], true);
    }

    private function requiredString(ActionEvent $event, string $field): string
    {
        $value = $event->payload[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new LifecycleException("Event {$event->kind} requires a non-empty {$field} payload field.");
        }
        return $value;
    }

    private function optionalString(ActionEvent $event, string $field): ?string
    {
        if (!array_key_exists($field, $event->payload)) {
            return null;
        }

        return $this->requiredString($event, $field);
    }

    private function requiredEpoch(ActionEvent $event): int
    {
        if ($event->epoch === null) {
            throw new LifecycleException("Event {$event->kind} requires an executor epoch.");
        }

        return $event->epoch;
    }

    private function hash(ActionEvent $event): ?string
    {
        return $this->hashChain?->hash($event);
    }
}
