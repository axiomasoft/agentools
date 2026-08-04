<?php

declare(strict_types=1);

namespace AgenTools\Tests\Action;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionJournal;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\ActionSnapshot;
use AgenTools\Action\ActionStatus;
use AgenTools\Action\Actor;
use AgenTools\Action\EventUpcaster;
use AgenTools\Action\EventUpcasterChain;
use AgenTools\Action\EffectRecord;
use AgenTools\Action\JournalHashChain;
use AgenTools\Action\JournalIntegrityException;
use AgenTools\Action\LifecycleException;
use AgenTools\Action\VersionConflict;
use AgenTools\Schema\Canonicalizer;
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

    /** ALC-30, ALC-31; the upcast is pure and leaves the recorded event untouched. */
    public function testItUpcastsOnlyWhileReadingTheJournal(): void
    {
        $recorded = $this->event(1, 0, 'action_created');
        $upcaster = new class implements EventUpcaster {
            public function supports(ActionEvent $event): bool
            {
                return $event->kind === 'action_created' && $event->eventVersion === 1;
            }

            public function upcast(ActionEvent $event): ActionEvent
            {
                return new ActionEvent($event->eventId, $event->actionId, $event->seq, $event->expectedPrevSeq, $event->timestamp, $event->actor, $event->kind, 2, $event->specRevision, $event->payload, $event->epoch, $event->previousHash);
            }
        };

        $state = (new ActionReducer(upcasters: new EventUpcasterChain([$upcaster])))->fold([$recorded]);

        self::assertSame(ActionStatus::Created, $state->status);
        self::assertSame(1, $recorded->eventVersion);
    }

    /** ALC-35, ALC-36, ALC-37; a changed record breaks the separate integrity failure. */
    public function testItRejectsATamperedHashChain(): void
    {
        $chain = new JournalHashChain(new Canonicalizer());
        $reducer = new ActionReducer(hashChain: $chain);
        $first = $this->event(1, 0, 'action_created');
        $state = $reducer->fold([$first]);
        $second = $this->event(2, 1, 'action_started')->withPreviousHash($state->previousHash);
        self::assertSame(ActionStatus::Running, $reducer->fold([$first, $second])->status);

        $this->expectException(JournalIntegrityException::class);
        $reducer->fold([$first, $this->event(2, 1, 'action_started')->withPreviousHash('sha-256:' . str_repeat('0', 64))]);
    }

    /** ALC-32, ALC-33; projection plus journal tail restores the same folded state. */
    public function testItRestoresFromSnapshotAndJournalTail(): void
    {
        $reducer = new ActionReducer();
        $firstTwo = [$this->event(1, 0, 'action_created'), $this->event(2, 1, 'action_started')];
        $snapshot = new ActionSnapshot($reducer->fold($firstTwo), '2026-08');
        $tail = [$this->event(3, 2, 'action_completed')];

        self::assertSame(
            $reducer->fold([...$firstTwo, ...$tail])->status,
            ActionJournal::restore($snapshot, $tail, $reducer)->status,
        );
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
