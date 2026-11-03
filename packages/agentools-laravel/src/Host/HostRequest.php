<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Host;

use AgenToolsPlatform\Core\Common\{InputPayload, SessionId, SourceIdentity};
use AgenToolsPlatform\Core\Security\Correlation;

/** Loaded by an authenticated host API, never deserialized from browser/job authority fields. */
final readonly class HostRequest
{
    public function __construct(public SessionId $session, public SourceIdentity $source,
        public InputPayload $payload, public string $purpose, public Correlation $correlation)
    {
        $session->tenant->assertSame($source->instance->tenant);
        $session->tenant->assertSame($payload->inputRef->tenant);
    }
}
