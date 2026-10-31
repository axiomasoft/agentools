<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Outbox;

use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\RepositoryContext;
use Throwable;

final readonly class Relay
{
    public function __construct(private RepositoryContext $environment, private OutboxRepository $outbox)
    {
    }

    /** @param callable(WakeSignal): void $publish */
    public function once(ExecutionContext $context, callable $publish): bool
    {
        $claim = $this->outbox->claim($context);
        if ($claim === null) {
            return false;
        }
        $this->environment->authorize($context);
        try {
            $publish($claim->signal); // Claim transaction has committed; no row lock is held.
        } catch (Throwable $error) {
            $this->outbox->unknown($context, $claim);
            throw $error;
        }
        $this->outbox->dispatched($context, $claim);
        return true;
    }
}
