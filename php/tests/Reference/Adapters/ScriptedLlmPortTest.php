<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Adapters;

use AgenTools\Error\ToolErrorCode;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Reference\Adapters\ScriptedLlmPort;
use PHPUnit\Framework\TestCase;

final class ScriptedLlmPortTest extends TestCase
{
    /** INT-002 §2.1; scripted llm_stub responses without network access. */
    public function testItReturnsScriptedStubFromInput(): void
    {
        $port = new ScriptedLlmPort();
        $stub = ['toolCall' => ['name' => 'demo_read_note', 'arguments' => ['note_id' => 'n-1']]];

        self::assertSame($stub, $port->respond(['llm_stub' => $stub]));
    }

    /** INT-002 §2.1; named fixtures load scripted responses. */
    public function testItLoadsNamedFixture(): void
    {
        $port = new ScriptedLlmPort();

        self::assertSame(
            ['toolCall' => ['name' => 'demo_read_note', 'arguments' => ['note_id' => 'note-1']]],
            $port->respond(['fixture' => 'simple_tool_call']),
        );
    }

    /** Negative 1/4: LLM_QUOTA_EXCEEDED becomes a tool execution error, not a protocol failure. */
    public function testItMapsQuotaExceededToToolExecutionError(): void
    {
        $port = new ScriptedLlmPort();

        $result = $port->respondAsToolResult(['error_code' => ScriptedLlmPort::QUOTA_STRUCTURAL_CODE]);

        self::assertTrue($result->isError());
        self::assertSame(ToolErrorCode::LimitExceeded->value, $result->error?->code);
        self::assertSame(ScriptedLlmPort::QUOTA_STRUCTURAL_CODE, $result->error?->context['structural_code'] ?? null);
    }

    /** Negative 1/4 variant: quota fixture also maps to tool execution error. */
    public function testItMapsQuotaFixtureToToolExecutionError(): void
    {
        $port = new ScriptedLlmPort();

        $result = $port->respondAsToolResult(['fixture' => 'quota_exceeded']);

        self::assertInstanceOf(ToolExecutionResult::class, $result);
        self::assertTrue($result->isError());
        self::assertSame(ToolErrorCode::LimitExceeded->value, $result->error?->code);
    }
}
