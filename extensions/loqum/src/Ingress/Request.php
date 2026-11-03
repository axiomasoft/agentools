<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Ingress;

use AgenToolsPlatform\Core\Common\{InstanceId, Ref, SessionId};
use AgenToolsPlatform\Core\Security\Correlation;

/** Resolved by an authenticated backend handle; never deserialized as browser authority. */
final readonly class Request
{
    public function __construct(public SessionId $session, public InstanceId $instance,
        public Transcript $transcript, public string $purpose, public Correlation $correlation,
        public ?Ref $conversation = null)
    {
        foreach ([$instance->tenant, $transcript->textRef->tenant, $conversation?->tenant] as $tenant) {
            if ($tenant !== null) { $session->tenant->assertSame($tenant); }
        }
    }
}
