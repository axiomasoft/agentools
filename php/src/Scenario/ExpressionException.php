<?php

declare(strict_types=1);

namespace AgenTools\Scenario;

/** A syntax, type, path, or deterministic budget failure in the closed expression language. */
final class ExpressionException extends \RuntimeException
{
    public function __construct(public readonly ?string $limitName, string $message)
    {
        parent::__construct($message);
    }

    public static function limit(string $name, int $maximum): self
    {
        return new self($name, "Expression limit {$name} ({$maximum}) was exceeded.");
    }
}
