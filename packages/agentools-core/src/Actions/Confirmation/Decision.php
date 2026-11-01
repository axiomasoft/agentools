<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Confirmation;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref, UtcInstant};

/** Only a constructor-injected host source supplies an authenticated affirmative event. */
final readonly class Decision
{
    public function __construct(public Ref $source, public PayloadHash $sourceHash, public Ref $actor,
        public PayloadHash $bindingHash, public string $channel, public UtcInstant $expiresAt)
    {
        $source->tenant->assertSame($actor->tenant);
        if (!in_array($channel, ['ui', 'voice'], true)) {
            throw new \InvalidArgumentException('An authenticated UI or voice event is required');
        }
    }
}
