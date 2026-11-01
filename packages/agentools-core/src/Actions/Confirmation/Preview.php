<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Confirmation;

use AgenToolsPlatform\Core\Common\Ref;

/** Disclosure requires the bound current host context; do not log this value. */
final readonly class Preview
{
    public function __construct(public Ref $proposal, public Binding $binding,
        public string $token, public string $presentationJson)
    {
        $proposal->tenant->assertSame($binding->action->tenant);
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['proposal' => $this->proposal->value, 'token' => '[redacted]', 'presentation' => '[redacted]'];
    }
}
