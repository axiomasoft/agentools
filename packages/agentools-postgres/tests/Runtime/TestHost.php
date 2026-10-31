<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Runtime;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, SessionId, TenantId};
use AgenToolsPlatform\Core\Security\{HostAuthorization, HostIdentity};
use PDO;

final class TestHost implements HostAuthorization
{
    public bool $denied = false;
    public string $policy = 'policy-1';
    public int $calls = 0;

    public function __construct(private PDO $pdo, public TenantId $tenant)
    {
    }

    public function authorize(SessionId $session, string $purpose): HostIdentity
    {
        ++$this->calls;
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Host IO under SQL transaction');
        }
        if ($this->denied || $session->tenant->value !== $this->tenant->value) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        return new HostIdentity($this->tenant, new Ref($this->tenant, 'actor'), null,
            new Ref($this->tenant, 'authorization'), $this->policy, new Ref($this->tenant, 'subject'),
            new Ref($this->tenant, 'consent'), new Ref($this->tenant, 'agent'));
    }
}
