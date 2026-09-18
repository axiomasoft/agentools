<?php

declare(strict_types=1);

namespace AgenTools\Tests\Ops;

use AgenTools\Action\ActionStatus;
use AgenTools\Action\EffectRecord;
use AgenTools\Action\EffectStatus;
use AgenTools\Action\FoldedAction;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Ops\CooperativeCancellation;
use AgenTools\Ops\TaskStatusMapper;
use AgenTools\Ops\TaskStatusMappingException;
use PHPUnit\Framework\TestCase;

final class TaskStatusMapperTest extends TestCase
{
    /** LOP-12; working status does not append journal events. */
    public function testWorkingStatusIsSilent(): void
    {
        $events = (new TaskStatusMapper())->map('working', $this->runningState());

        self::assertSame([], $events);
    }

    /** LOP-13; input_required is handled as clarification input. */
    public function testInputRequiredRecordsClarificationInput(): void
    {
        $events = (new TaskStatusMapper())->map('input_required', $this->runningState(), ['name' => 'prompt', 'value' => 'yes']);

        self::assertSame('input_recorded', $events[0]['kind']);
    }

    /** LOP-14; input_required during applying is a typed error. */
    public function testInputRequiredWhileApplyingFails(): void
    {
        $state = new FoldedAction(
            'act-1',
            ActionStatus::Applying,
            4,
            1,
            effects: ['eff-1' => new EffectRecord('eff-1', EffectStatus::Requested, 1, 4)],
        );

        try {
            (new TaskStatusMapper())->map('input_required', $state);
            self::fail('Expected a typed mapping failure.');
        } catch (TaskStatusMappingException $exception) {
            self::assertSame(
                ToolErrorCode::TaskInputRequiredWhileApplying->value,
                $exception->result->error?->code,
            );
        }
    }

    /** LOP-25; cancelled with unresolved effect does not emit action_cancelled. */
    public function testCancelledWithUnresolvedEffectIsBlocked(): void
    {
        $state = new FoldedAction(
            'act-1',
            ActionStatus::Applying,
            4,
            1,
            effects: ['eff-1' => new EffectRecord('eff-1', EffectStatus::Requested, 1, 4)],
            cancellationRequested: true,
        );

        $events = (new TaskStatusMapper())->map('cancelled', $state);

        self::assertSame([], $events);
    }

    /** LOP-20, LOP-21; cancellation is derived from the journal, not a separate flag. */
    public function testCooperativeCancellationUsesJournalProjection(): void
    {
        $state = new FoldedAction('act-1', ActionStatus::Running, 3, 1, cancellationRequested: true);
        $cancellation = new CooperativeCancellation();

        self::assertTrue($cancellation->isInEffect($state));
        self::assertTrue($cancellation->canRecordCancelled($state));
    }

    private function runningState(): FoldedAction
    {
        return new FoldedAction('act-1', ActionStatus::Running, 3, 1);
    }
}
