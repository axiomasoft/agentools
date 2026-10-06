<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

use AgenTools\Contracts\ExternalEffectProbe;
use AgenTools\Contracts\IdempotencyStore;
use AgenTools\Contracts\ToolPort;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolExecutionException;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Schema\Canonicalizer;

/** Supported fenced invoke path. Store and catalog are required. */
final class IdempotencyCoordinator implements ToolPort
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly ToolIdempotencyCatalog $catalog,
        private readonly ToolPort $tools,
        private readonly Canonicalizer $canonicalizer,
        private readonly string $tenantId,
        private readonly string $actionId,
        private readonly \DateInterval $leaseDuration = new \DateInterval('PT30S'),
        private readonly ?ExternalEffectProbe $probe = null,
        private readonly ?\Closure $clock = null,
    ) {
        if ($tenantId === '' || $actionId === '') {
            throw new \InvalidArgumentException('Coordinator tenant id and action id must not be empty.');
        }
    }

    /** @param array<string, mixed> $arguments */
    public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
    {
        try {
            return $this->invoke($tool, $arguments, $idempotencyKey);
        } catch (ToolExecutionException $exception) {
            return ToolExecutionResult::failure($exception->error);
        }
    }

    /** @param array<string, mixed> $arguments */
    private function invoke(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
    {
        $capability = $this->catalog->capability($tool);
        if ($capability === ToolIdempotencyCapability::Unknown && $this->catalog->isHighRisk($tool)) {
            throw IdempotencyFailure::capabilityUnknown($tool);
        }
        $key = IdempotencyKey::required($idempotencyKey);
        $now = $this->now();
        $request = new IdempotencyRequest(
            new IdempotencyScope($this->tenantId, $tool, $key),
            ArgumentsFingerprint::fromArguments($arguments, $this->canonicalizer),
            $this->actionId,
        );
        $acquisition = $this->store->acquire($request, $now, $this->leaseDuration);
        if ($acquisition->state === DeduplicationState::DuplicateCompleted) {
            return $this->replay($acquisition);
        }
        if ($acquisition->state === DeduplicationState::ConcurrentInFlight) {
            return ToolExecutionResult::failure($acquisition->error ?? IdempotencyFailure::inFlight($key->value)->error);
        }
        $lease = $acquisition->lease;
        if ($lease === null) {
            throw new \LogicException('A new acquisition must carry a lease.');
        }
        if ($acquisition->takeover) {
            $takeover = $this->takeoverDecision($capability, $tool, $idempotencyKey, $lease, $now);
            if ($takeover !== null) {
                return $takeover;
            }
        }
        $this->store->heartbeat($lease, $now, $this->leaseDuration);
        $result = $this->invokeTool($tool, $arguments, $idempotencyKey);
        $stored = $this->stored($result);
        $digest = StoredResultDigest::fromJcs($this->canonicalizer->canonicalize($stored->error?->toArray() ?? $stored->value ?? []));
        try {
            if ($result->isError()) {
                $this->store->fail($lease, $stored, $digest, $this->now());
            } else {
                $this->store->complete($lease, $stored, $digest, $this->now());
            }
        } catch (IdempotencyFailure $failure) {
            if ($failure->error->code === IdempotencyFailure::lostLease()->error->code) {
                return ToolExecutionResult::failure($failure->error);
            }
            throw $failure;
        }

        return $result;
    }

    /**
     * A typed tool-execution error thrown by the tool is the same outcome as a returned failure
     * (IDE-23): it is recorded under the lease and replayed as is (IDE-12, IDE-13) instead of
     * leaving the key in flight until the lease expires. Any other throwable means the effect
     * is unknown; it propagates and the lease is left to expire so that the takeover protocol
     * decides by tool capability.
     *
     * @param array<string, mixed> $arguments
     */
    private function invokeTool(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
    {
        try {
            return $this->tools->call($tool, $arguments, $idempotencyKey);
        } catch (ToolExecutionException $exception) {
            return ToolExecutionResult::failure($exception->error);
        }
    }

    /** @return ToolExecutionResult|null null means re-invoke the tool */
    private function takeoverDecision(
        ToolIdempotencyCapability $capability,
        string $tool,
        string $idempotencyKey,
        IdempotencyLease $lease,
        \DateTimeImmutable $now,
    ): ?ToolExecutionResult {
        if ($capability === ToolIdempotencyCapability::NativeKey) {
            return null;
        }
        if ($capability === ToolIdempotencyCapability::ReadBeforeWrite) {
            if ($this->probe === null) {
                return $this->storeAmbiguous($lease, $now);
            }
            $found = $this->probe->find($tool, $idempotencyKey);
            if ($found === null) {
                return null;
            }
            $digest = StoredResultDigest::fromJcs($this->canonicalizer->canonicalize($found->error?->toArray() ?? $found->value ?? []));
            $this->store->complete($lease, $found, $digest, $now);
            if ($found->error !== null) {
                return ToolExecutionResult::failure($found->error);
            }

            return ToolExecutionResult::success($found->value ?? []);
        }

        return $this->storeAmbiguous($lease, $now);
    }

    private function storeAmbiguous(IdempotencyLease $lease, \DateTimeImmutable $now): ToolExecutionResult
    {
        $error = IdempotencyFailure::ambiguousEffect()->error;
        $stored = new StoredToolResult(error: $error);
        $digest = StoredResultDigest::fromJcs($this->canonicalizer->canonicalize($error->toArray()));
        $this->store->fail($lease, $stored, $digest, $now);

        return ToolExecutionResult::failure($error);
    }

    private function replay(IdempotencyAcquisition $acquisition): ToolExecutionResult
    {
        $stored = $acquisition->storedResult;
        if ($stored === null) {
            throw new \LogicException('A completed duplicate must carry a stored result.');
        }
        if ($stored->error !== null) {
            return ToolExecutionResult::failure($stored->error);
        }

        return ToolExecutionResult::success($stored->value ?? []);
    }

    private function stored(ToolExecutionResult $result): StoredToolResult
    {
        if ($result->error !== null) {
            return new StoredToolResult(error: $result->error);
        }

        return new StoredToolResult(value: $result->value ?? []);
    }

    private function now(): \DateTimeImmutable
    {
        if ($this->clock !== null) {
            $value = ($this->clock)();
            if (!$value instanceof \DateTimeImmutable) {
                throw new \InvalidArgumentException('Coordinator clock must return DateTimeImmutable.');
            }

            return $value;
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
