<?php

declare(strict_types=1);

namespace AgenTools\Tests\Error;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ErrorCategoryOpenEnum;
use AgenTools\Error\OpenEnum;
use AgenTools\Error\ValueLifecycle;
use PHPUnit\Framework\TestCase;

final class OpenEnumTest extends TestCase
{
    /** IDE-35, IDE-36; known core values are accepted without the product fallback. */
    public function testItAcceptsKnownCoreValues(): void
    {
        $value = new OpenEnum('write', ['read', 'draft', 'write', 'irreversible']);

        self::assertTrue($value->isKnown());
        self::assertSame(ValueLifecycle::Active, $value->lifecycle());
    }

    /** IDE-35, IDE-37; product extension values use the fallback branch. */
    public function testItAcceptsProductExtensionValues(): void
    {
        $value = new OpenEnum('acme.custom_period_close', ['read', 'draft', 'write', 'irreversible']);

        self::assertFalse($value->isKnown());
    }

    /** IDE-36; values outside both branches are rejected. */
    public function testItRejectsValuesOutsideKnownAndFallbackBranches(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpenEnum('not-a-valid-open-enum', ['read', 'draft', 'write', 'irreversible']);
    }

    /** IDE-45; deprecated values remain readable. */
    public function testItAcceptsDeprecatedValuesForReaders(): void
    {
        $value = new OpenEnum('legacy', ['legacy', 'active'], ['legacy' => ValueLifecycle::Deprecated]);

        self::assertSame(ValueLifecycle::Deprecated, $value->lifecycle());
    }

    /** IDE-47; removed values are rejected after the deprecation window. */
    public function testItRejectsRemovedValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpenEnum('retired', ['retired'], ['retired' => ValueLifecycle::Removed]);
    }

    /** IDE-46; producers must not emit deprecated values in new records. */
    public function testItRejectsDeprecatedValuesForProducers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpenEnum::producerValue('legacy', ['legacy'], ['legacy' => ValueLifecycle::Deprecated]);
    }

    /** IDE-25, IDE-33; unknown product categories remain valid open-enum members. */
    public function testErrorCategoryOpenEnumAcceptsProductCategories(): void
    {
        $category = ErrorCategoryOpenEnum::parse('fina.custom_category');

        self::assertFalse($category->isKnown());
        self::assertSame('fina.custom_category', $category->value);
        self::assertSame(false, ErrorCategory::defaultRetryable(ErrorCategory::VALIDATION));
    }
}
