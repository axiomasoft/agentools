<?php

declare(strict_types=1);

namespace AgenTools\Tests\Scenario;

use AgenTools\Contracts\ToolPort;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Scenario\ScenarioOutcome;
use AgenTools\Scenario\ScenarioRunner;
use PHPUnit\Framework\TestCase;

final class ScenarioRunnerTest extends TestCase
{
    /** SCN-23–SCN-25: successful steps compensate in reverse order on a later failure. */
    public function testItRunsCompensationsInReverseOrder(): void
    {
        $port = new class implements ToolPort {
            /** @var list<string> */ public array $calls = [];
            public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
            {
                $this->calls[] = $tool;
                return $tool === 'fail' ? ToolExecutionResult::failure(new ToolError('demo.validation.failed', 'validation', false, 'failed')) : ToolExecutionResult::success(['reservation_id' => $tool]);
            }
        };
        $scenario = ['scenario_id' => 'pay', 'spec_rev' => '2026-08', 'steps' => [
            ['id' => 'one', 'tool' => 'one', 'input_map' => [], 'compensate' => ['tool' => 'undo-one', 'input_map' => []]],
            ['id' => 'two', 'tool' => 'two', 'input_map' => [], 'compensate' => ['tool' => 'undo-two', 'input_map' => []]],
            ['id' => 'send', 'tool' => 'fail', 'input_map' => [], 'on_semantic_fail' => ['action' => 'abort']],
        ]];

        $result = (new ScenarioRunner())->run($scenario, [], $port);

        self::assertSame(ScenarioOutcome::Compensated, $result->outcome);
        self::assertSame(['one', 'two', 'fail', 'undo-two', 'undo-one'], $port->calls);
    }

    /** SCN-26, SCN-27: a failed compensation stops the rollback and stays distinct from FAILED. */
    public function testItReportsASeparateCompensationFailure(): void
    {
        $port = new class implements ToolPort {
            public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
            {
                return in_array($tool, ['fail', 'undo-two'], true)
                    ? ToolExecutionResult::failure(new ToolError('demo.validation.failed', 'validation', false, 'failed'))
                    : ToolExecutionResult::success([]);
            }
        };
        $scenario = ['scenario_id' => 'pay', 'spec_rev' => '2026-08', 'steps' => [
            ['id' => 'one', 'tool' => 'one', 'input_map' => [], 'compensate' => ['tool' => 'undo-one', 'input_map' => []]],
            ['id' => 'two', 'tool' => 'two', 'input_map' => [], 'compensate' => ['tool' => 'undo-two', 'input_map' => []]],
            ['id' => 'send', 'tool' => 'fail', 'input_map' => []],
        ]];

        $result = (new ScenarioRunner())->run($scenario, [], $port);

        self::assertSame(ScenarioOutcome::CompensationFailed, $result->outcome);
        self::assertSame('two', $result->stoppedAtStepId);
    }

    /** SCN-11: continue records no result for the failed step and runs the following step. */
    public function testItContinuesWithoutExposingAFailedStepResult(): void
    {
        $port = new class implements ToolPort {
            /** @var list<string> */ public array $calls = [];
            public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
            {
                $this->calls[] = $tool;
                return $tool === 'fails' ? ToolExecutionResult::failure(new ToolError('demo.validation.failed', 'validation', false, 'failed')) : ToolExecutionResult::success(['ok' => true]);
            }
        };
        $scenario = ['scenario_id' => 'continue', 'spec_rev' => '2026-08', 'steps' => [
            ['id' => 'fails', 'tool' => 'fails', 'input_map' => [], 'on_semantic_fail' => ['action' => 'continue']],
            ['id' => 'next', 'tool' => 'next', 'input_map' => []],
        ]];

        $result = (new ScenarioRunner())->run($scenario, [], $port);

        self::assertSame(ScenarioOutcome::Completed, $result->outcome);
        self::assertSame(['fails', 'next'], $port->calls);
        self::assertArrayNotHasKey('fails', $result->stepResults);
    }
}
