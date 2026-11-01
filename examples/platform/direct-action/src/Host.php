<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\DirectAction;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, SessionId, TenantId};
use AgenToolsPlatform\Core\Security\{HostAuthorization, HostIdentity};
use PDO;

/** Synthetic example host. An embedding application supplies its authenticated host port. */
final class Host implements HostAuthorization
{
    public bool $denied = false;
    public string $revision = 'policy-1';

    public function __construct(private PDO $pdo, public TenantId $tenant, public string $actor = 'actor') {}

    public function authorize(SessionId $session, string $purpose): HostIdentity
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Host authorization under SQL lock'); }
        $this->tenant->assertSame($session->tenant);
        if ($this->denied || $purpose !== 'direct.read') { throw new ContractViolation(ErrorCode::AccessDenied); }
        return new HostIdentity($this->tenant, new Ref($this->tenant, $this->actor), null,
            new Ref($this->tenant, 'host:authorization'), $this->revision);
    }
}
