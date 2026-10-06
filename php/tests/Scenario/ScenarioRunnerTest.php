<?php

declare(strict_types=1);

namespace AgenTools\Tests\Scenario;

use AgenTools\Contracts\ToolPort;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Idempotency\IdempotencyCoordinator;
use AgenTools\Idempotency\IdempotencyKey;
use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\ToolIdempotencyCapability;
use AgenTools\Idempotency\ToolIdempotencyCatalog;
use AgenTools\Schema\Canonicalizer;
use AgenTools\Scenario\ScenarioOutcome;
use AgenTools\Scenario\ScenarioRunner;
use AgenTools\TestKit\InMemoryDeduplicationStore;
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

        $result = (new ScenarioRunner())->run($scenario, [], $port, self::runKey());

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

        $result = (new ScenarioRunner())->run($scenario, [], $port, self::runKey());

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

        $result = (new ScenarioRunner())->run($scenario, [], $port, self::runKey());

        self::assertSame(ScenarioOutcome::Completed, $result->outcome);
        self::assertSame(['fails', 'next'], $port->calls);
        self::assertArrayNotHasKey('fails', $result->stepResults);
    }

    /**
     * IDE-1, IDE-4, SCN-8, SCN-25; step and compensation keys are derived from the run key.
     * The expected values are shared with the Dart runner test (cross-language parity).
     */
    public function testItDerivesIde4KeysFromTheRunKey(): void
    {
        $port = $this->recordingPort(['send']);

        (new ScenarioRunner())->run($this->compensatedScenario(), [], $port, self::runKey());

        self::assertSame([
            ['one', 'at1:_Tdvrr8jtfeFMFwi4OSkm3ql7ITj5UoefYfT-51davE'],
            ['send', 'at1:EfAEOX0HLeq9g7oYoqR2C9NJ3RJ51FM_HpOzvG32iA4'],
            ['undo-one', 'at1:7553ycecufcG9QBkwC4GrxjDbvI4As12iuK32ZC6g_U'],
        ], $port->calls);
    }

    /** IDE-1; the same run key re-presents the same keys, a new run key is a new intent. */
    public function testRunKeysSeparateIntents(): void
    {
        $first = $this->recordingPort([]);
        $again = $this->recordingPort([]);
        $other = $this->recordingPort([]);
        $scenario = ['scenario_id' => 'pay', 'spec_rev' => '2026-09', 'steps' => [['id' => 'one', 'tool' => 'one', 'input_map' => []]]];

        (new ScenarioRunner())->run($scenario, [], $first, self::runKey());
        (new ScenarioRunner())->run($scenario, [], $again, self::runKey());
        (new ScenarioRunner())->run($scenario, [], $other, new IdempotencyKey('at1:run-0002-abcdef'));

        self::assertSame($first->calls, $again->calls);
        self::assertNotSame($first->calls[0][1], $other->calls[0][1]);
    }

    /** IDE-4, IDE-12; runner keys are accepted by the fenced coordinator and deduplicate a re-run. */
    public function testItRunsThroughTheIdempotencyCoordinator(): void
    {
        $tools = $this->recordingPort([]);
        $coordinator = new IdempotencyCoordinator(
            new InMemoryDeduplicationStore(new DeduplicationPolicy(new \DateInterval('P1D'), new \DateInterval('PT0S'), new \DateInterval('PT0S'))),
            new ToolIdempotencyCatalog(['one' => ToolIdempotencyCapability::NativeKey], ['one' => 'write']),
            $tools,
            new Canonicalizer(),
            'tenant-1',
            'action-1',
        );
        $scenario = ['scenario_id' => 'pay', 'spec_rev' => '2026-09', 'steps' => [['id' => 'one', 'tool' => 'one', 'input_map' => []]]];

        $first = (new ScenarioRunner())->run($scenario, [], $coordinator, self::runKey());
        $replay = (new ScenarioRunner())->run($scenario, [], $coordinator, self::runKey());

        self::assertSame(ScenarioOutcome::Completed, $first->outcome);
        self::assertSame(ScenarioOutcome::Completed, $replay->outcome);
        self::assertCount(1, $tools->calls);
    }

    private static function runKey(): IdempotencyKey
    {
        return new IdempotencyKey('at1:run-0001-abcdef');
    }

    /** @return array<string, mixed> */
    private function compensatedScenario(): array
    {
        return ['scenario_id' => 'pay', 'spec_rev' => '2026-09', 'steps' => [
            ['id' => 'one', 'tool' => 'one', 'input_map' => [], 'compensate' => ['tool' => 'undo-one', 'input_map' => []]],
            ['id' => 'send', 'tool' => 'send', 'input_map' => []],
        ]];
    }

    /**
     * @param list<string> $failing
     * @return ToolPort&object{calls: list<array{string, string}>}
     */
    private function recordingPort(array $failing): ToolPort
    {
        return new class ($failing) implements ToolPort {
            /** @var list<array{string, string}> */
            public array $calls = [];

            /** @param list<string> $failing */
            public function __construct(private readonly array $failing)
            {
            }

            public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
            {
                $this->calls[] = [$tool, $idempotencyKey];

                return in_array($tool, $this->failing, true)
                    ? ToolExecutionResult::failure(new ToolError('demo.validation.failed', 'validation', false, 'failed'))
                    : ToolExecutionResult::success(['ok' => true]);
            }
        };
    }
}
