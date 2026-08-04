<?php

declare(strict_types=1);

namespace AgenTools\Schema;

final class SchemaValidationException extends \InvalidArgumentException
{
    /** @param list<int|string> $path */
    public function __construct(public readonly array $path, public readonly string $rule, string $message)
    {
        parent::__construct($message);
    }
}
