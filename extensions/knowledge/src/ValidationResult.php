<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

final readonly class ValidationResult
{
    /** @param list<string> $errors */
    public function __construct(public string $status, public array $errors = [])
    {
        if (!in_array($status, ['validated', 'failed', 'unknown'], true)
            || ($status === 'validated' && $errors !== [])) {
            throw new \InvalidArgumentException('Invalid validation result');
        }
    }
}
