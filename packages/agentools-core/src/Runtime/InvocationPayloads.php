<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Host-owned encrypted immutable payload storage, never a default in-memory production dependency.
 * put must durably register the same tenant/session payload_ref before returning; same key/different
 * bytes conflicts. Reads enforce current disclosure policy. SHA-256 is of the exact JSON bytes.
 */
interface InvocationPayloads
{
    public function put(ExecutionContext $context, Ref $key, PayloadHash $hash, string $json): void;
    /** Null means authoritative absence, outages throw. */
    public function find(ExecutionContext $context, Ref $key): ?string;
    public function get(ExecutionContext $context, Ref $key): string;
}
