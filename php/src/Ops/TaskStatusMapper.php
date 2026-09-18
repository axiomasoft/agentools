<?php

declare(strict_types=1);

namespace AgenTools\Ops;

use AgenTools\Action\ActionStatus;
use AgenTools\Action\FoldedAction;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Error\ToolExecutionResult;

/** Maps counterparty task status to journal append instructions (LOP-12). */
final class TaskStatusMapper
{
    /**
     * @param array<string, mixed>|null $inputPayload
     *
     * @return list<array{kind: string, payload: array<string, mixed>}>
     */
    public function map(string $status, FoldedAction $state, ?array $inputPayload = null): array
    {
        /** @var list<array{kind: string, payload: array<string, mixed>}> $events */
        $events = match ($status) {
            'working' => [],
            'input_required' => $this->mapInputRequired($state, $inputPayload),
            'completed' => [['kind' => 'action_completed', 'payload' => []]],
            'failed' => [['kind' => 'action_failed', 'payload' => []]],
            'cancelled' => $this->mapCancelled($state),
            default => [],
        };

        return $events;
    }

    /**
     * LOP-14; input_required during applying is a typed error, not clarification.
     *
     * @param array<string, mixed>|null $inputPayload
     *
     * @return list<array{kind: string, payload: array<string, mixed>}>
     */
    public function mapInputRequired(FoldedAction $state, ?array $inputPayload): array
    {
        if ($state->status === ActionStatus::Applying) {
            throw new TaskStatusMappingException(ToolExecutionResult::failure(new ToolError(
                ToolErrorCode::TaskInputRequiredWhileApplying->value,
                'precondition',
                false,
                'Task requested input while an external effect was unresolved.',
            )));
        }

        return [[
            'kind' => 'input_recorded',
            'payload' => $inputPayload ?? ['name' => 'task_input', 'value' => null],
        ]];
    }

    /**
     * LOP-25; cancelled maps to action_cancelled only without unresolved effects.
     *
     * @return list<array{kind: string, payload: array<string, mixed>}>
     */
    private function mapCancelled(FoldedAction $state): array
    {
        if ($state->hasUnresolvedEffect()) {
            return [];
        }

        return [['kind' => 'action_cancelled', 'payload' => []]];
    }
}

final class TaskStatusMappingException extends \RuntimeException
{
    public function __construct(public readonly ToolExecutionResult $result)
    {
        parent::__construct('Task status mapping failed.');
    }
}
