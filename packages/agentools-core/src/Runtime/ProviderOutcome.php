<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};
use AgenToolsPlatform\Core\Models\ModelResponse;

/** Verified owner lookup evidence bound to the ORIGINAL request, never a fresh generation. */
final readonly class ProviderOutcome
{
    public function __construct(public ModelResponse $response, public PayloadHash $requestHash, public Ref $evidence)
    {
        $response->invocationId->tenant->assertSame($evidence->tenant);
    }
}
