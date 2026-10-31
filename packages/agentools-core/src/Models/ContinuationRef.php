<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\UtcInstant;

/** Handle to externally encrypted opaque state. Never portable conversation memory. */
final readonly class ContinuationRef
{
    public function __construct(public Ref $encryptedRef, public string $provider, public string $account,
        public string $region, public UtcInstant $expiresAt)
    {
        if ($provider === '' || $account === '' || $region === '') { throw new \InvalidArgumentException('Continuation binding is required'); }
    }

    public function assertUsable(Deployment $deployment, UtcInstant $now): void
    {
        $encryptedRef = $this->encryptedRef;
        if ($encryptedRef->tenant->value !== $deployment->ref->tenant->value || $this->provider !== $deployment->provider
            || $this->account !== $deployment->account || $this->region !== $deployment->region
            || Time::compare($now, $this->expiresAt) >= 0) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(\AgenToolsPlatform\Core\Common\ErrorCode::AccessDenied);
        }
    }
}
