<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,SessionId};
use AgenToolsPlatform\Core\Security\{HostAuthorization,HostIdentity};

/** Test-control grants are explicit session+purpose facts, not a blanket allow-all profile. */
final class FakeAuthority implements HostAuthorization
{
    /** @var array<string,HostIdentity> */
    private array $grants=[];
    private ?HostIdentity $cache=null;
    public function grant(SessionId $session,string $purpose,HostIdentity $identity): void
    {
        $session->tenant->assertSame($identity->tenant);$this->grants[$this->key($session,$purpose)]=$identity;
    }
    public function revoke(SessionId $session,string $purpose): void { unset($this->grants[$this->key($session,$purpose)]); }
    public function authorize(SessionId $session,string $purpose): HostIdentity
    {
        return $this->cache=$this->grants[$this->key($session,$purpose)]??throw new ContractViolation(ErrorCode::AccessDenied);
    }
    public function reset(): void { $this->cache=null; }
    public function hasCachedIdentity(): bool { return $this->cache!==null; }
    private function key(SessionId $session,string $purpose): string { return json_encode([$session->toArray(),$purpose],JSON_THROW_ON_ERROR); }
}
