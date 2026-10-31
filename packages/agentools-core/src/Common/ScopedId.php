<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

abstract readonly class ScopedId
{
    protected const string KIND = 'ref';
    protected const bool DATED_OPAQUE = false;

    final public function __construct(public TenantId $tenant, public string $value)
    {
        if ($value === '' || (!static::DATED_OPAQUE && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,255}$/D', $value) !== 1)) {
            throw new \InvalidArgumentException('IDs and refs must be opaque nonempty identifiers');
        }
    }

    /** @return array{tenant_id: string, kind: string, id: string} */
    final public function toArray(): array
    {
        return ['tenant_id' => $this->tenant->value, 'kind' => static::KIND, 'id' => $this->value];
    }

    final public function assertSame(self $other): void
    {
        $this->tenant->assertSame($other->tenant);
        if (static::KIND !== $other::KIND || $this->value !== $other->value) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict);
        }
    }
}
