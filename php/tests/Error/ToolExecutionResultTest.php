<?php

declare(strict_types=1);

namespace AgenTools\Tests\Error;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ProtocolException;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolExecutionResult;
use PHPUnit\Framework\TestCase;

final class ToolExecutionResultTest extends TestCase
{
    /** IDE-23; a business failure is a structured tool result, not a protocol exception. */
    public function testItReturnsBusinessFailureAsAToolResult(): void
    {
        $error = new ToolError('billing.validation.period_closed', ErrorCategory::VALIDATION, false, 'Период закрыт');
        $result = ToolExecutionResult::failure($error);

        self::assertTrue($result->isError());
        self::assertSame($error, $result->error);
    }

    /** IDE-23; protocol failures retain a separate exception channel. */
    public function testProtocolFailureIsNotAToolResult(): void
    {
        $this->expectException(ProtocolException::class);
        throw new ProtocolException('Malformed transport envelope.');
    }
}
