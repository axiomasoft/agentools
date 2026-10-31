<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

use AgenToolsPlatform\Postgres\Runtime\{Connection, StorageFailure};
use Throwable;

/** Explicit worker composition: the supplied reset clears host actor/policy/profile/retrieval state. */
final class JobBoundary
{
    private bool $running = false;

    public function __construct(private readonly Connection $db, private readonly EncryptedPayloadStore $store,
        private readonly \Closure $resetHostState)
    {
    }

    /**
     * @template T
     * @param callable(): T $job
     * @return T
     */
    public function run(callable $job): mixed
    {
        if ($this->running) {
            throw new \LogicException('Nested worker job refused');
        }
        $this->db->assertIdle();
        $this->running = true;
        try {
            $this->reset();
            return $job();
        } finally {
            try {
                $this->reset();
            } finally {
                $this->running = false;
            }
        }
    }

    private function reset(): void
    {
        try {
            try {
                $this->db->resetSession();
            } finally {
                try {
                    $this->store->reset();
                } finally {
                    ($this->resetHostState)();
                }
            }
        } catch (Throwable) {
            throw new StorageFailure();
        }
    }
}
