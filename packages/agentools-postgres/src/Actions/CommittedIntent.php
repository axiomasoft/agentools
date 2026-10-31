<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Actions;

use AgenToolsPlatform\Core\Common\ActionId;

/** Loaded only at an idle/committed boundary; not an exactly-once owner receipt. */
final readonly class CommittedIntent
{
    /** @param array<string, mixed> $authorizationContext */
    public function __construct(public ActionId $action, public EffectIntent $request,
        public int $claimEpoch, public array $authorizationContext)
    {
    }
}
