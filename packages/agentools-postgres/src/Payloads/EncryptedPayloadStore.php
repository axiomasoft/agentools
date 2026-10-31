<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

use AgenToolsPlatform\Core\Security\ExecutionContext;

/** External owner port. No production resolver, fake bytes or transcript store is supplied here. */
interface EncryptedPayloadStore
{
    public function read(ExecutionContext $context, PayloadHandle $handle): string;

    /** Must advertise verified same-key idempotent deletion/status semantics before automatic recovery. */
    public function supportsIdempotentPurge(): bool;

    public function purge(ExecutionContext $context, PayloadHandle $handle, string $operationKey): void;

    /** Clear decrypted bytes, handles, keys and credential caches even on failure. */
    public function reset(): void;
}
