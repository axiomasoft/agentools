<?php

declare(strict_types=1);

namespace AgenTools\Scenario;

use AgenTools\Contracts\ToolPort;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Idempotency\IdempotencyKey;

/**
 * SCN-1–SCN-11 and SCN-20–SCN-29: a linear interpreter with no goto, loops,
 * or hidden control-flow. Successful compensable steps form the only rollback
 * register and are therefore reversed deterministically.
 */
final class ScenarioRunner
{
    private const KEY_DOMAIN = 'agentools.scenario-step-key.v1';
    private int $executedSteps = 0;

    public function __construct(private readonly ExpressionEvaluator $expressions = new ExpressionEvaluator())
    {
    }

    /**
     * @param array<string, mixed> $scenario
     * @param array<string, mixed> $context
     */
    public function run(array $scenario, array $context, ToolPort $tools, IdempotencyKey|string|null $runKey = null, ?string $runId = null): ScenarioResult
    {
        $this->executedSteps = 0;
        if ($runKey !== null && $runId !== null) {
            throw new \InvalidArgumentException('Provide only one scenario run identity.');
        }
        $runId ??= $runKey ?? bin2hex(random_bytes(16));
        if ($runId === '') {
            throw new \InvalidArgumentException('A scenario run identity must not be empty.');
        }
        if (!in_array($scenario['spec_rev'] ?? null, ['2026-08', '2026-09'], true)) {
            throw new \InvalidArgumentException('A scenario must declare a supported specification revision.');
        }
        $scenarioId = $this->stringField($scenario, 'scenario_id');
        $steps = $scenario['steps'] ?? null;
        if (!is_array($steps) || count($steps) > 64) {
            throw new ExpressionException('scenario.max_steps', 'Scenario step limit scenario.max_steps (64) was exceeded.');
        }

        /** @var array<string, array<string, mixed>> $results */
        $results = [];
        /** @var list<array{id: string, compensate: array<string, mixed>}> $compensations */
        $compensations = [];
        $ids = [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                throw new \InvalidArgumentException('Scenario steps must be objects.');
            }
            $stepId = $this->stringField($step, 'id');
            if (isset($ids[$stepId])) {
                throw new \InvalidArgumentException('Scenario step identifiers must be unique.');
            }
            $ids[$stepId] = true;

            try {
                if (isset($step['when'])) {
                    $when = $this->expressions->evaluate($this->stringField($step, 'when'), $context, $results);
                    if (!is_bool($when)) {
                        throw new ExpressionException(null, 'A scenario when expression must evaluate to boolean.');
                    }
                    if (!$when) {
                        continue;
                    }
                }
                $arguments = $this->arguments($step['input_map'] ?? [], $context, $results);
                $result = $this->invoke($tools, $this->stringField($step, 'tool'), $arguments, $this->key($runId, $scenarioId, $stepId, 'execute'), $step['on_semantic_fail'] ?? null);
                if ($result->isError()) {
                    if ($this->failureAction($step, false) === 'continue') {
                        continue;
                    }
                    return $this->handleFailure($runId, $scenarioId, $stepId, $step, $compensations, $tools, $context, $results, false);
                }
                $results[$stepId] = $result->value ?? [];
                if (isset($step['compensate']) && is_array($step['compensate'])) {
                    $compensations[] = ['id' => $stepId, 'compensate' => $step['compensate']];
                }
            } catch (\Throwable $error) {
                if ($this->failureAction($step, true) === 'continue') {
                    continue;
                }
                return $this->handleFailure($runId, $scenarioId, $stepId, $step, $compensations, $tools, $context, $results, true);
            }
        }
        return new ScenarioResult(ScenarioOutcome::Completed, $results);
    }

    /**
     * @param array<mixed, mixed> $step
     * @param list<array{id: string, compensate: array<mixed, mixed>}> $compensations
     * @param array<string, array<string, mixed>> $results
     * @param array<string, mixed> $context
     */
    private function handleFailure(IdempotencyKey|string $runId, string $scenarioId, string $stepId, array $step, array $compensations, ToolPort $tools, array $context, array $results, bool $technical): ScenarioResult
    {
        $action = $this->failureAction($step, $technical);
        // Retrying is handled at the invocation boundary in a future journal-aware runner;
        // an exhausted retry is normatively equivalent to abort (SCN-10).
        if ($action === 'continue') {
            return new ScenarioResult(ScenarioOutcome::Failed, []);
        }
        foreach (array_reverse($compensations) as $entry) {
            try {
                $arguments = $this->arguments($entry['compensate']['input_map'] ?? [], $context, $results);
                $tool = $this->stringField($entry['compensate'], 'tool');
                $result = $this->call($tools, $tool, $arguments, $this->key($runId, $scenarioId, $entry['id'], 'compensate'));
                if ($result->isError()) {
                    return new ScenarioResult(ScenarioOutcome::CompensationFailed, [], $entry['id']);
                }
            } catch (\Throwable) {
                return new ScenarioResult(ScenarioOutcome::CompensationFailed, [], $entry['id']);
            }
        }
        return new ScenarioResult($compensations === [] ? ScenarioOutcome::Failed : ScenarioOutcome::Compensated, []);
    }

    /**
     * @param mixed $mapping
     * @param array<string, mixed> $context
     * @param array<string, array<string, mixed>> $results
     * @return array<string, mixed>
     */
    private function arguments(mixed $mapping, array $context, array $results): array
    {
        if (!is_array($mapping)) {
            throw new \InvalidArgumentException('input_map must be an object.');
        }
        $arguments = [];
        foreach ($mapping as $name => $expression) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException('input_map keys must be strings.');
            }
            $arguments[$name] = is_string($expression) ? $this->expressions->evaluate($expression, $context, $results) : $expression;
        }
        return $arguments;
    }

    private function key(IdempotencyKey|string $runId, string $scenarioId, string $stepId, string $operation): string
    {
        if ($runId instanceof IdempotencyKey) {
            return self::stepKey($runId, $scenarioId, $stepId, $operation === 'execute' ? 'step' : 'compensation');
        }
        return 'at1:' . hash('sha256', (new \AgenTools\Schema\Canonicalizer())->canonicalize([$runId, $scenarioId, $stepId, $operation]));
    }

    /** @param array<string, mixed> $arguments */
    private function invoke(ToolPort $tools, string $tool, array $arguments, string $key, mixed $route): ToolExecutionResult
    {
        $attempts = is_array($route) && ($route['action'] ?? null) === 'retry' ? $route['attempts'] ?? null : 1;
        if (!is_int($attempts) || $attempts < 1) {
            throw new \InvalidArgumentException('A retry route requires a positive finite attempts count.');
        }
        $result = $this->call($tools, $tool, $arguments, $key);
        for ($attempt = 1; $result->isError() && $attempt < $attempts; ++$attempt) {
            $result = $this->call($tools, $tool, $arguments, $key);
        }
        return $result;
    }

    /**
     * at1 key = base64url(SHA-256(domain || run key || scenario id || step id || role)) where
     * every part is length-prefixed with its UTF-8 byte count, so distinct tuples never collide.
     *
     * @param 'step'|'compensation' $role
     */
    private static function stepKey(IdempotencyKey $runKey, string $scenarioId, string $stepId, string $role): string
    {
        $input = '';
        foreach ([self::KEY_DOMAIN, $runKey->value, $scenarioId, $stepId, $role] as $part) {
            $input .= strlen($part) . ':' . $part . ',';
        }

        return (new IdempotencyKey('at1:' . rtrim(strtr(base64_encode(hash('sha256', $input, true)), '+/', '-_'), '=')))->value;
    }

    /** @param array<mixed, mixed> $value */
    private function stringField(array $value, string $field): string
    {
        if (!is_string($value[$field] ?? null) || $value[$field] === '') {
            throw new \InvalidArgumentException("Scenario requires non-empty {$field}.");
        }
        return $value[$field];
    }

    /** @param array<mixed, mixed> $step */
    private function failureAction(array $step, bool $technical): string
    {
        $route = $step[$technical ? 'on_technical_fail' : 'on_semantic_fail'] ?? ['action' => 'abort'];
        if (!is_array($route) || !is_string($route['action'] ?? null) || !in_array($route['action'], ['abort', 'retry', 'continue'], true)) {
            throw new \InvalidArgumentException('Failure routes must be abort, retry, or continue.');
        }
        return $route['action'];
    }

    /** @param array<string, mixed> $arguments */
    private function call(ToolPort $tools, string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
    {
        if (++$this->executedSteps > 64) {
            throw new ExpressionException('scenario.max_steps', 'Scenario step limit scenario.max_steps (64) was exceeded.');
        }
        return $tools->call($tool, $arguments, $idempotencyKey);
    }
}
