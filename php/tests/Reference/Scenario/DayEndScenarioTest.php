<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Scenario;

use AgenTools\Reference\Scenario\DayEndOrchestrator;
use AgenTools\Reference\Support\InjectableClock;
use AgenTools\Reference\Tools\DayReportStore;
use PHPUnit\Framework\TestCase;

final class DayEndScenarioTest extends TestCase
{
    /** INT-002 §5; full product path issues exactly one confirmation request. */
    public function testItCompletesDayEndFlowWithSingleConfirmation(): void
    {
        $store = new DayReportStore();
        $store->seedActivities('tenant-a', '2026-09-19', [['id' => 'act-1', 'summary' => 'Call']]);

        $script = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/reference/scenario/day_end_report.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($script);

        $vectorScript = [
            ['user_input' => 'day end'],
            ['llm_stub' => ['toolCall' => ['tool' => 'slice_read_day_activities', 'args' => ['date' => '2026-09-19']]]],
            ['llm_stub' => ['toolCall' => ['tool' => 'slice_draft_day_report', 'args' => ['date' => '2026-09-19', 'summary' => 'Итог', 'body' => 'Call']]]],
            ['clarification' => [
                'call_id' => 'c1',
                'request_state' => 's1',
                'requests' => ['client_name' => ['prompt' => 'Client?']],
                'answer_call_id' => 'c2',
                'answers' => ['client_name' => 'ACME'],
            ]],
            ['llm_stub' => ['toolCall' => ['tool' => 'slice_write_day_report', 'args' => [
                'report_id' => 'r-1',
                'date' => '2026-09-19',
                'body' => 'Done',
            ]]]],
            ['approval' => 'approve'],
        ];

        $orchestrator = new DayEndOrchestrator($store, 'tenant-a', new InjectableClock(new \DateTimeImmutable('2026-09-19T10:00:00+00:00')));
        $outcome = $orchestrator->run($vectorScript, 'test-vector');

        self::assertSame('completed', $outcome['final_state']);
        self::assertSame(1, $outcome['metrics']->confirmationRequests);
        self::assertSame(1, $outcome['metrics']->proposalCount);
        self::assertTrue($store->hasReport('tenant-a', 'r-1'));
    }
}
