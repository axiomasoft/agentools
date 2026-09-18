<?php

declare(strict_types=1);

namespace AgenTools\Persistence;

use AgenTools\Action\SessionStateProjection;
use AgenTools\Contracts\SessionStore;

/** Process-local projection cache. Production stores must keep projections consistent with the event log. */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, SessionStateProjection> */
    private array $projections = [];

    public function load(string $actionId): ?SessionStateProjection
    {
        return $this->projections[$actionId] ?? null;
    }

    public function save(SessionStateProjection $projection): void
    {
        $this->projections[$projection->actionId] = $projection;
    }
}
