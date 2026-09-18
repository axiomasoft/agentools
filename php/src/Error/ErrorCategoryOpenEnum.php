<?php

declare(strict_types=1);

namespace AgenTools\Error;

/** Open category enumeration with core const branches and a product fallback pattern. */
final class ErrorCategoryOpenEnum
{
    /** @var list<string> */
    public const KNOWN = [
        ErrorCategory::VALIDATION,
        ErrorCategory::PERMISSION,
        ErrorCategory::NOT_FOUND,
        ErrorCategory::PRECONDITION,
        ErrorCategory::CONFLICT,
        ErrorCategory::UNAVAILABLE,
        ErrorCategory::BUDGET,
        ErrorCategory::INTERNAL,
    ];

    /**
     * @param array<string, ValueLifecycle> $lifecycle
     */
    public static function parse(string $category, array $lifecycle = []): OpenEnum
    {
        return new OpenEnum($category, self::KNOWN, $lifecycle);
    }
}
