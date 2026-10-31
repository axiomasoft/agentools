<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Runtime;

use AgenToolsPlatform\Core\Common\{InstanceId, PayloadHash, Ref, SessionId};

/** First-slice code configuration pins, not a profile publication API. */
final readonly class SessionBinding
{
    public function __construct(
        public SessionId $session,
        public InstanceId $instance,
        public Ref $configRef,
        public PayloadHash $configHash,
        public PayloadHash $registryHash,
        public Ref $bundleRef,
        public PayloadHash $bundleHash,
        public ?Ref $historyRef = null,
    ) {
        foreach ([$instance, $configRef, $bundleRef, $historyRef] as $ref) {
            if ($ref !== null) {
                $session->tenant->assertSame($ref->tenant);
            }
        }
    }
}
