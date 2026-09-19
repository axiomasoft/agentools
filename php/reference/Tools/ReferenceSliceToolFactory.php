<?php

declare(strict_types=1);

namespace AgenTools\Reference\Tools;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Reference\Support\JsonGuard;
use AgenTools\Tool\ToolDescriptor;

/** Registers read/draft/write domain tools for the reference product slice. */
final class ReferenceSliceToolFactory
{
    public function __construct(private readonly DayReportStore $store)
    {
    }

    public function registerOn(RegistryToolPort $port, string $tenantId): void
    {
        $port->register($this->readDescriptor(), function (array $arguments, string $idempotencyKey) use ($tenantId): ToolExecutionResult {
            $date = JsonGuard::string($arguments['date'] ?? null);
            if ($date === '') {
                return $this->missingArgument('date is required');
            }

            return ToolExecutionResult::success([
                'activities' => $this->store->activities($tenantId, $date),
            ]);
        });

        $port->register($this->draftDescriptor(), function (array $arguments, string $idempotencyKey): ToolExecutionResult {
            $date = JsonGuard::string($arguments['date'] ?? null);
            $summary = JsonGuard::string($arguments['summary'] ?? null);
            if ($date === '' || $summary === '') {
                return $this->missingArgument('date and summary are required');
            }

            return ToolExecutionResult::success([
                'preview' => [
                    'summary' => $summary,
                    'changes' => [['field' => 'body', 'value' => JsonGuard::string($arguments['body'] ?? null, $summary)]],
                ],
            ]);
        });

        $port->register($this->writeDescriptor(), function (array $arguments, string $idempotencyKey) use ($tenantId): ToolExecutionResult {
            $reportId = JsonGuard::string($arguments['report_id'] ?? null);
            $body = JsonGuard::string($arguments['body'] ?? null);
            $date = JsonGuard::string($arguments['date'] ?? null);
            if ($reportId === '' || $body === '' || $date === '') {
                return $this->missingArgument('report_id, body and date are required');
            }

            $record = $this->store->commitReport($tenantId, $reportId, $body, $date);

            return ToolExecutionResult::success([
                'report' => $record,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    public function readDescriptor(): ToolDescriptor
    {
        return $this->descriptor(
            'slice_read_day_activities',
            'read',
            ['slice.read'],
            "Purpose: Reads captured day activities.\nCall when: A work date is known.\nRequired arguments: date\nExample: slice_read_day_activities({\"date\":\"2026-09-19\"})",
            ['date' => ['type' => 'string']],
            readOnly: true,
        );
    }

    public function draftDescriptor(): ToolDescriptor
    {
        return $this->descriptor(
            'slice_draft_day_report',
            'draft',
            ['slice.draft'],
            "Purpose: Drafts a day report from activities.\nCall when: Activities are available.\nRequired arguments: date, summary\nExample: slice_draft_day_report({\"date\":\"2026-09-19\",\"summary\":\"Итог дня\"})",
            ['date' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'body' => ['type' => 'string']],
            readOnly: false,
        );
    }

    public function writeDescriptor(): ToolDescriptor
    {
        return $this->descriptor(
            'slice_write_day_report',
            'write',
            ['slice.write'],
            "Purpose: Persists the approved day report.\nCall when: Preview is accepted.\nRequired arguments: report_id, date, body\nExample: slice_write_day_report({\"report_id\":\"r-1\",\"date\":\"2026-09-19\",\"body\":\"...\"})",
            ['report_id' => ['type' => 'string'], 'date' => ['type' => 'string'], 'body' => ['type' => 'string']],
            readOnly: false,
            idempotent: true,
        );
    }

    private function missingArgument(string $message): ToolExecutionResult
    {
        return ToolExecutionResult::failure(new ToolError(
            'agentools.precondition.missing_argument',
            ErrorCategory::PRECONDITION,
            false,
            $message,
        ));
    }

    /**
     * @param list<string> $permissions
     * @param array<string, array<string, string>> $properties
     */
    private function descriptor(
        string $name,
        string $sideEffect,
        array $permissions,
        string $description,
        array $properties,
        bool $readOnly,
        bool $idempotent = false,
    ): ToolDescriptor {
        return ToolDescriptor::fromArray([
            'name' => $name,
            'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties)],
            'annotations' => [
                'readOnlyHint' => $readOnly,
                'destructiveHint' => $sideEffect === 'irreversible',
                'idempotentHint' => $idempotent,
                'openWorldHint' => false,
            ],
            '_meta' => ['dev.agentools/action' => [
                'side_effect' => $sideEffect,
                'permissions_hint' => $permissions,
                'idempotent_commit' => $idempotent,
            ]],
        ]);
    }
}
