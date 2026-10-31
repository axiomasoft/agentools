<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\InputPayload;
use AgenToolsPlatform\Core\Common\SourceIdentity;

final readonly class TrustedInput
{
    public function __construct(public ExecutionContext $context, public SourceIdentity $source, public InputPayload $payload)
    {
        $context->identity->tenant->assertSame($source->instance->tenant);
        $context->identity->tenant->assertSame($payload->inputRef->tenant);
    }
}
