<?php

declare(strict_types=1);

namespace AgenTools\Tests\Effects;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionJournal;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\Actor;
use AgenTools\Action\EffectRecord;
use AgenTools\Action\EffectStatus;
use AgenTools\Action\StaleExecutorEpoch;
use AgenTools\Effects\EffectApplier;
use AgenTools\Effects\EffectCommitter;
use PHPUnit\Framework\TestCase;

final class EffectCommitterTest extends TestCase
{
    /** ALC-24, ALC-25; registry entry is written before product code can invoke an effect. */
    public function testItRecordsTheEffectBeforeCallingTheExternalPort(): void
    {
        $journal = $this->journal();
        $applier = new class implements EffectApplier {
            public ?EffectRecord $received = null;

            public function apply(EffectRecord $effect): void
            {
                $this->received = $effect;
            }
        };

        $updated = (new EffectCommitter())->commit($journal, $this->event(4, 3, 'effect_requested', ['effect_id' => 'payment-1'], 1), $applier);

        self::assertSame(EffectStatus::Requested, $applier->received?->status);
        self::assertSame(EffectStatus::Requested, $updated->state()->effects['payment-1']->status);
    }

    /** ALC-25; a confirmed effect is never sent to the external port twice. */
    public function testItSkipsAnAlreadyConfirmedEffect(): void
    {
        $journal = $this->journal();
        foreach ([
            $this->event(4, 3, 'effect_requested', ['effect_id' => 'payment-1'], 1),
            $this->event(5, 4, 'effect_confirmed', ['effect_id' => 'payment-1'], 1),
        ] as $event) {
            $journal = $journal->append($event);
        }
        $applier = new class implements EffectApplier {
            public int $calls = 0;

            public function apply(EffectRecord $effect): void
            {
                ++$this->calls;
            }
        };

        $result = (new EffectCommitter())->commit($journal, $this->event(6, 5, 'effect_requested', ['effect_id' => 'payment-1'], 1), $applier);

        self::assertSame(0, $applier->calls);
        self::assertSame($journal->events(), $result->events());
    }

    /** ALC-26, ALC-27; after failover an executor from the old epoch is rejected. */
    public function testItRejectsAZombieExecutorEpoch(): void
    {
        $this->expectException(StaleExecutorEpoch::class);
        (new ActionReducer())->fold([
            $this->event(1, 0, 'action_created'),
            $this->event(2, 1, 'epoch_started', [], 1),
            $this->event(3, 2, 'action_started'),
            $this->event(4, 3, 'epoch_started', [], 2),
            $this->event(5, 4, 'effect_requested', ['effect_id' => 'payment-1'], 1),
        ]);
    }

    private function journal(): ActionJournal
    {
        $journal = new ActionJournal();
        foreach ([
            $this->event(1, 0, 'action_created'),
            $this->event(2, 1, 'epoch_started', [], 1),
            $this->event(3, 2, 'action_started'),
        ] as $event) {
            $journal = $journal->append($event);
        }
        return $journal;
    }

    /** @param array<string, mixed> $payload */
    private function event(int $seq, int $previous, string $kind, array $payload = [], ?int $epoch = null): ActionEvent
    {
        return new ActionEvent("event-{$seq}", 'action-1', $seq, $previous, '2026-08-03T09:14:22.118Z', new Actor('system', 'worker-1'), $kind, 1, '2026-08', $payload, $epoch);
    }
}
