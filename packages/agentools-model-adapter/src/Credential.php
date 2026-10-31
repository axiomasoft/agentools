<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter;

use AgenToolsPlatform\Core\Common\{Ref, UtcInstant, ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Models\{Deployment, Time};

final readonly class Credential
{
    public function __construct(public Ref $ref, public string $provider, public string $account,
        public string $region, public UtcInstant $expiresAt, #[\SensitiveParameter] private string $apiKey)
    {
        if ($apiKey === '' || preg_match('/[\r\n\x00]/', $apiKey)) { throw new \InvalidArgumentException('Invalid credential'); }
    }

    public function assertBound(Ref $requested, Deployment $deployment, UtcInstant $now): void
    {
        $requested->assertSame($this->ref);
        $requested->tenant->assertSame($deployment->ref->tenant);
        if ($this->provider !== $deployment->provider || $this->account !== $deployment->account
            || $this->region !== $deployment->region || Time::compare($now, $this->expiresAt) >= 0) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }

    public function apiKey(): string { return $this->apiKey; }
    /** @return array<string, string> */
    public function __debugInfo(): array { return ['credential_ref' => $this->ref->value, 'api_key' => '[redacted]']; }
}
