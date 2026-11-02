<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Dependencies;

use AgenToolsPlatform\Knowledge\Scope;

interface DeletionStore
{
    /** True only for a newly durably started attempt. Replays never repeat external IO. */
    public function beginAttempt(Scope $scope, DeletionRequest $request, string $attempt): bool;
    public function result(Scope $scope, DeletionRequest $request, string $attempt): ?DeletionResult;
    public function finishAttempt(Scope $scope, DeletionRequest $request, string $attempt, DeletionResult $result): void;
}
