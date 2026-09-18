<?php

declare(strict_types=1);

namespace AgenTools\Tests\Error;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use PHPUnit\Framework\TestCase;

final class ToolErrorTest extends TestCase
{
    /** IDE-26; code and category must agree at runtime. */
    public function testCodeCategoryAgreementIsValidatedAtRuntime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ToolError('billing.validation.period_closed', ErrorCategory::CONFLICT, false, 'Period is closed');
    }

    /** IDE-25, IDE-29; optional context is preserved on structured tool errors. */
    public function testStructuredToolErrorKeepsOptionalData(): void
    {
        $error = new ToolError('billing.validation.period_closed', ErrorCategory::VALIDATION, false, 'Period is closed', context: ['period' => '2026-08']);

        self::assertSame(['period' => '2026-08'], $error->context);
    }

    /** IDE-22, IDE-26; product error codes outside agentools are accepted on equal footing. */
    public function testItAcceptsProductErrorCodes(): void
    {
        $error = ToolError::fromArray([
            'code' => 'fina.validation.period_closed',
            'category' => 'validation',
            'retryable' => false,
            'title' => 'Период закрыт',
        ]);

        self::assertSame('fina.validation.period_closed', $error->code);
        self::assertSame(ErrorCategory::VALIDATION, $error->category);
    }
}
