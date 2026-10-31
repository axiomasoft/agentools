<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, PayloadHash, Ref};
use AgenToolsPlatform\Core\Security\{ExecutionContext, HostAuthorization};

/** Buffered disclosure contract helper: returns refs only, never retains sensitive payloads. */
final class ScriptedDisclosure
{
    /** @var array<string, PayloadHash> */
    private array $allowed = [];
    public function __construct(private HostAuthorization $host) {}
    public function allow(Ref $payload, PayloadHash $hash): void { $this->allowed[$this->key($payload)] = $hash; }
    public function reset(): void { $this->allowed = []; }
    public function release(ExecutionContext $context, Ref $payload, PayloadHash $hash): Ref
    {
        $context->identity->tenant->assertSame($payload->tenant);
        $context->assertCurrent($this->host);
        $expected = $this->allowed[$this->key($payload)] ?? throw new ContractViolation(ErrorCode::AccessDenied);
        $expected->assertSame($hash);
        return $payload;
    }
    private function key(Ref $ref): string { return json_encode($ref->toArray(), JSON_THROW_ON_ERROR); }
}
