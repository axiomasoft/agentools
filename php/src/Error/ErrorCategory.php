<?php

declare(strict_types=1);

namespace AgenTools\Error;

final class ErrorCategory
{
    public const VALIDATION = 'validation';
    public const PERMISSION = 'permission';
    public const NOT_FOUND = 'not_found';
    public const PRECONDITION = 'precondition';
    public const CONFLICT = 'conflict';
    public const UNAVAILABLE = 'unavailable';
    public const BUDGET = 'budget';
    public const INTERNAL = 'internal';

    /** @var array<string, bool> */
    private const DEFAULT_RETRYABLE = [
        self::VALIDATION => false,
        self::PERMISSION => false,
        self::NOT_FOUND => false,
        self::PRECONDITION => false,
        self::CONFLICT => true,
        self::UNAVAILABLE => true,
        self::BUDGET => false,
        self::INTERNAL => false,
    ];

    public static function defaultRetryable(string $category): ?bool
    {
        return self::DEFAULT_RETRYABLE[$category] ?? null;
    }
}
