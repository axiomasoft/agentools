<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

final readonly class AuthorizationDecision
{
    private function __construct(public string $result)
    {
    }

    public static function allow(): self
    {
        return new self('allow');
    }

    public static function deny(): self
    {
        return new self('deny');
    }

    public function isAllowed(): bool
    {
        return $this->result === 'allow';
    }
}
