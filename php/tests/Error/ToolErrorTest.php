<?php

declare(strict_types=1);

namespace AgenTools\Tests\Error;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use PHPUnit\Framework\TestCase;

final class ToolErrorTest extends TestCase
{
    public function testCodeCategoryAgreementIsValidatedAtRuntime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ToolError('billing.validation.period_closed', ErrorCategory::CONFLICT, false, 'Period is closed');
    }

    public function testStructuredToolErrorKeepsOptionalData(): void
    {
        $error = new ToolError('billing.validation.period_closed', ErrorCategory::VALIDATION, false, 'Period is closed', context: ['period' => '2026-08']);

        self::assertSame('2026-08', $error->toArray()['context']['period']);
    }
}
