<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, SessionId};
use AgenToolsPlatform\Core\Security\{HostAuthorization, HostIdentity};

/** External host double; core ExecutionContext still performs its real refresh checks. */
final class ScriptedHostAuthorization implements HostAuthorization
{
    private ?HostIdentity $identity = null;
    private ?SessionId $session = null;
    public bool $revoked = true;

    public function beginJob(HostIdentity $identity, SessionId $session): void
    {
        $this->endJob();
        $identity->tenant->assertSame($session->tenant);
        $this->identity = $identity;
        $this->session = $session;
        $this->revoked = false;
    }
    public function endJob(): void { $this->identity = null; $this->session = null; $this->revoked = true; }
    public function authorize(SessionId $session, string $purpose): HostIdentity
    {
        if ($this->identity === null || $this->session === null || $this->revoked || $purpose === '') {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        $this->session->assertSame($session);
        return $this->identity;
    }
}
