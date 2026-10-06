<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

use AgenTools\Contracts\ToolPort;
use AgenTools\Error\ToolExecutionResult;

/** Records scenario tool invocations through harness probes. */
final class HarnessToolPort implements ToolPort
{
    /** @var \Closure(array<string,mixed>):array<string,mixed> */
    private readonly \Closure $probe;

    /** @param callable(array<string,mixed>):array<string,mixed> $probe */
    public function __construct(
        callable $probe,
        private readonly string $vectorId,
    ) {
        $this->probe = $probe(...);
    }

    /** @param array<string, mixed> $arguments */
    public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
    {
        ($this->probe)([
            'type' => 'probe_call',
            'vector_id' => $this->vectorId,
            'probe_id' => 'scenario-' . $tool . '-' . $idempotencyKey,
            'probe_kind' => 'tool_boundary',
            'operation' => ['tool' => $tool, 'phase' => 'prepare', 'args' => $arguments],
        ]);

        return ToolExecutionResult::success(['ok' => true]);
    }
}
