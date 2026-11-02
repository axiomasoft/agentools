<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Dependencies;

use AgenToolsPlatform\Knowledge\Scope;

/** One explicitly requested attempt; no background effect loop or erasure inference. */
final readonly class DeletionWorker
{
    public function __construct(private DeletionStore $store, private DeletionTransport $transport) {}
    public function attempt(Scope $scope, DeletionRequest $request, string $attempt): DeletionResult
    {
        if (!$this->store->beginAttempt($scope,$request,$attempt)) {
            return $this->store->result($scope,$request,$attempt) ?? new DeletionResult('unknown');
        }
        if ($request->support !== 'supported') { $result = new DeletionResult($request->support === 'unsupported' ? 'unsupported' : 'unknown'); }
        else {
            try { $result = $this->transport->delete($request); }
            catch (\Throwable) { $result = new DeletionResult('unknown'); }
        }
        $this->store->finishAttempt($scope,$request,$attempt,$result);
        return $result;
    }
}
