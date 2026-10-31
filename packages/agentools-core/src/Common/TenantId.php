<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

final readonly class TenantId
{
    public function __construct(public string $value)
    {
        if (strtolower($value) === 'global' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('A concrete tenant ID is required');
        }
    }

    public function assertSame(self $other): void
    {
        if ($this->value !== $other->value) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied);
        }
    }
}
