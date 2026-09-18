<?php

declare(strict_types=1);

namespace AgenTools\Tests\Ops;

use AgenTools\Action\ActionJournal;
use AgenTools\Action\ActionStatus;
use AgenTools\Action\Actor;
use AgenTools\Ops\JournalAppender;
use AgenTools\Ops\LongOperationCoordinator;
use AgenTools\Ops\LongOperationPath;
use AgenTools\Ops\TaskBinding;
use AgenTools\Persistence\FixedClock;
use PHPUnit\Framework\TestCase;

final class LongOperationCoordinatorTest extends TestCase
{
    private Actor $actor;

    protected function setUp(): void
    {
        $this->actor = new Actor('agent', 'agent-fina-1');
    }

    /** LOP-1, LOP-2; both paths are declared without making tasks mandatory. */
    public function testItSupportsTaskAndSynchronouslyPaths(): void
    {
        $coordinator = new LongOperationCoordinator();

        self::assertSame(
            [LongOperationPath::TaskBacked, LongOperationPath::Synchronous],
            $coordinator->availablePaths(),
        );
    }

    /** LOP-10; task id is journaled before the first status observation. */
    public function testItRecordsTaskBindingBeforeFirstObservation(): void
    {
        $journal = $this->baseJournal();
        $coordinator = new LongOperationCoordinator();
        $binding = new TaskBinding('act-1', 'task-99', 'poll');

        $coordinator->bindTask($journal, $binding);
        $coordinator->observeTaskStatus($journal, 'working');

        $kinds = array_map(static fn ($event) => $event->kind, $journal->journal()->events());
        self::assertSame(['action_created', 'action_started', 'task_bound'], $kinds);
    }

    /** LOP-10; binding after observation is rejected. */
    public function testItRejectsLateTaskBinding(): void
    {
        $journal = $this->baseJournal();
        $coordinator = new LongOperationCoordinator();
        $coordinator->observeTaskStatus($journal, 'working');

        $this->expectException(\LogicException::class);
        $coordinator->bindTask($journal, new TaskBinding('act-1', 'task-late'));
    }

    /** LOP-3, LOP-5; synchronous path observes progress via action state without task events. */
    public function testSynchronouslyPathUsesActionStateWithoutTaskBinding(): void
    {
        $journal = $this->baseJournal();
        $coordinator = new LongOperationCoordinator();
        $coordinator->runSynchronously($journal, [['kind' => 'effect_requested', 'payload' => ['effect_id' => 'eff-sync-1', 'tool_name' => 'pay']]]);

        $state = $journal->journal()->state();
        self::assertSame(ActionStatus::Applying, $state->status);
        $events = $journal->journal()->events();
        foreach ($events as $event) {
            self::assertNotSame('task_bound', $event->kind);
        }
    }

    /** LOP-15, LOP-16; unresolved effects move to reconciliation after binding loss. */
    public function testBindingLossWithUnresolvedEffectRequiresReconciliation(): void
    {
        $journal = $this->baseJournal();
        $journal->append('effect_requested', ['effect_id' => 'eff-1', 'tool_name' => 'pay'], 1);
        $coordinator = new LongOperationCoordinator();
        $coordinator->bindTask($journal, new TaskBinding('act-1', 'task-1'));
        $coordinator->recordBindingLost($journal, new TaskBinding('act-1', 'task-1', lostReason: 'expired'));

        self::assertSame(ActionStatus::ReconciliationRequired, $journal->journal()->state()->status);
    }

    /** LOP-20, LOP-23; cancellation request does not finish the action synchronously. */
    public function testCancellationRequestLeavesActionRunning(): void
    {
        $journal = $this->baseJournal();
        $journal->append('effect_requested', ['effect_id' => 'eff-1', 'tool_name' => 'pay'], 1);
        $coordinator = new LongOperationCoordinator();
        $coordinator->requestCancellation($journal);

        $state = $journal->journal()->state();
        self::assertSame(ActionStatus::Applying, $state->status);
        self::assertTrue($state->cancellationRequested);
    }

    /** LOP-7; fractional progress fields are forbidden in long-operation payloads. */
    public function testItRejectsFractionalProgressClaims(): void
    {
        $coordinator = new LongOperationCoordinator();

        $this->expectException(\InvalidArgumentException::class);
        $coordinator->assertNoFractionalProgressClaims(['per'.'cent' => 50]);
    }

    private function baseJournal(): JournalAppender
    {
        $journal = new ActionJournal([
            new \AgenTools\Action\ActionEvent(
                'ev-act-1-0001',
                'act-1',
                1,
                0,
                '2026-08-05T10:00:00+00:00',
                $this->actor,
                'action_created',
                1,
                '2026-08',
                [],
            ),
            new \AgenTools\Action\ActionEvent(
                'ev-act-1-0002',
                'act-1',
                2,
                1,
                '2026-08-05T10:00:01+00:00',
                $this->actor,
                'action_started',
                1,
                '2026-08',
                [],
            ),
        ]);

        return new JournalAppender(
            $journal,
            'act-1',
            $this->actor,
            new FixedClock(new \DateTimeImmutable('2026-08-05T10:00:02+00:00')),
        );
    }
}
