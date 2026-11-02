<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Dependencies;

interface DeletionTransport
{
    /** Explicit host adapter; request id MUST be the stable provider idempotency key across attempts. */
    public function delete(DeletionRequest $request): DeletionResult;
}
