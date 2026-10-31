<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests;

use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\SessionId;
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Core\Security\HostAuthorization;
use AgenToolsPlatform\Core\Security\HostIdentity;

final class TestHost implements HostAuthorization
{
    public int $calls = 0;
    public bool $denied = false;
    public string $revision = 'policy-1';

    public function __construct(public TenantId $tenant, public string $actor = 'actor-a')
    {
    }

    public function authorize(SessionId $session, string $purpose): HostIdentity
    {
        ++$this->calls;
        if ($this->denied) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        return new HostIdentity($this->tenant, new Ref($this->tenant, $this->actor), null,
            new Ref($this->tenant, 'authorization-1'), $this->revision);
    }
}
