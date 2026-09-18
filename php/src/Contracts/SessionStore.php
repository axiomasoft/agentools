<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Action\SessionStateProjection;

/** Materialized action-state projection. The event log remains the source of truth. */
interface SessionStore
{
    public function load(string $actionId): ?SessionStateProjection;

    public function save(SessionStateProjection $projection): void;
}
