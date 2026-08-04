<?php

declare(strict_types=1);

namespace AgenTools\Tests\Action;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionJournal;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\ActionStatus;
use AgenTools\Action\Actor;
use AgenTools\Action\EffectRecord;
use AgenTools\Action\LifecycleException;
use AgenTools\Action\VersionConflict;
use PHPUnit\Framework\TestCase;

final class ActionReducerTest extends TestCase
{
    public function testItFoldsLifecycleFromJournalOnly(): void
    {
        $journal = new ActionJournal();
        foreach ([
            $this->event(1, 0, 'action_created'),
            $this->event(2, 1, 'epoch_started', [], 1),
            $this->event(3, 2, 'action_started'),
            $this->event(4, 3, 'effect_requested', ['effect_id' => 'payment-1', 'tool_name' => 'payment_send'], 1),
            $this->event(5, 4, 'effect_confirmed', ['effect_id' => 'payment-1', 'external_ref' => 'pay-9'], 1),
            $this->event(6, 5, 'action_completed'),
        ] as $event) {
            $journal = $journal->append($event);
        }

        $state = $journal->state();
        self::assertSame(ActionStatus::Completed, $state->status);
        self::assertSame(6, $state->seq);
        $effect = $state->effects['payment-1'] ?? null;
        self::assertInstanceOf(EffectRecord::class, $effect);
        self::assertSame('pay-9', $effect->externalRef);
        self::assertTrue($state->status->isTerminal());
    }

    public function testItPreservesUnknownEventsWithoutChangingState(): void
    {
        $reducer = new ActionReducer();
        $state = $reducer->fold([
            $this->event(1, 0, 'action_created'),
            $this->event(2, 1, 'vendor.future_event', ['future_member' => ['deep' => true]]),
        ]);

        self::assertSame(ActionStatus::Created, $state->status);
        self::assertSame(2, $state->seq);
        self::assertSame(['future_member' => ['deep' => true]], $this->event(2, 1, 'vendor.future_event', ['future_member' => ['deep' => true]])->toArray()['payload']);
    }

    public function testGuardOrderReportsVersionConflictBeforeInvalidTransition(): void
    {
        $this->expectException(VersionConflict::class);

        (new ActionReducer())->fold([
            $this->event(1, 0, 'action_created'),
            $this->event(2, 0, 'action_completed'),
        ]);
    }

    public function testItRejectsEventAfterTerminalAction(): void
    {
        $this->expectException(LifecycleException::class);
        (new ActionReducer())->fold([
            $this->event(1, 0, 'action_created'),
            $this->event(2, 1, 'action_started'),
            $this->event(3, 2, 'action_completed'),
            $this->event(4, 3, 'input_recorded', ['name' => 'now', 'value' => 'later']),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function event(int $seq, int $previous, string $kind, array $payload = [], ?int $epoch = null): ActionEvent
    {
        return new ActionEvent(
            "event-{$seq}",
            'action-1',
            $seq,
            $previous,
            '2026-08-03T09:14:22.118Z',
            new Actor('system', 'worker-1'),
            $kind,
            1,
            '2026-08',
            $payload,
            $epoch,
        );
    }
}
