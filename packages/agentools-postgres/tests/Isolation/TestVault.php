<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Isolation;

use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Payloads\{EncryptedPayloadStore, PayloadHandle};
use PDO;

/** Test-only external owner. Persistent backend and per-job credential/cache state are distinct. */
final class TestVault implements EncryptedPayloadStore
{
    /** @var array<string, string> */
    public array $backend = [];
    /** @var list<array{tenant: string, slot: string, operation: string}> */
    public array $deletions = [];
    public int $reads = 0;
    public int $resets = 0;
    public bool $supported = true;
    public bool $failRead = false;
    public bool $failReset = false;
    public bool $loseDeleteReceipt = false;
    public ?\Closure $onRead = null;
    public ?\Closure $onPurge = null;
    public ?string $credential = null;
    public ?string $cachedBytes = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function read(ExecutionContext $context, PayloadHandle $handle): string
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Vault IO under SQL transaction');
        }
        ++$this->reads;
        $this->credential = $context->session->tenant->value . ':SECRET-credential';
        if ($this->failRead) {
            throw new \RuntimeException('SECRET-vault-response');
        }
        $bytes = $this->backend[$context->session->tenant->value . ':' . $handle->slot->value] ?? throw new \RuntimeException('SECRET-missing');
        $this->cachedBytes = $bytes;
        if ($this->onRead !== null) { ($this->onRead)(); }
        return $bytes;
    }

    public function supportsIdempotentPurge(): bool { return $this->supported; }

    public function purge(ExecutionContext $context, PayloadHandle $handle, string $operationKey): void
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Vault purge under SQL transaction');
        }
        $this->deletions[] = ['tenant' => $context->session->tenant->value, 'slot' => $handle->slot->value, 'operation' => $operationKey];
        unset($this->backend[$context->session->tenant->value . ':' . $handle->slot->value]);
        if ($this->onPurge !== null) { ($this->onPurge)(); }
        if ($this->loseDeleteReceipt) {
            throw new \RuntimeException('SECRET-delete-receipt-lost');
        }
    }

    public function reset(): void
    {
        ++$this->resets;
        $this->credential = null;
        $this->cachedBytes = null;
        if ($this->failReset) {
            throw new \RuntimeException('SECRET-reset-failed');
        }
    }
}
