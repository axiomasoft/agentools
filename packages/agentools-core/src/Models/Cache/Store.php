<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;
use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};

/** Tenant-bound durable technical metadata. Provider IO never runs inside a store transaction. */
interface Store
{
    public function assertIdle(): void;
    public function get(Key $key): ?Descriptor;
    public function put(Descriptor $descriptor): void;
    public function touch(Key $key,UtcInstant $now): bool;
    /** Commit local denial and invalidation before returning the provider-deletion intent. */
    public function invalidate(Key $key,string $reason,UtcInstant $now): ?Deletion;
    public function saveDeletion(Deletion $deletion): void;
    /** Fence competing delete workers and persist uncertain provider exposure before IO. */
    public function claimDeletion(Deletion $deletion): bool;
    public function nextDeletion(): ?Deletion;
    /** @return list<Key> */
    public function idle(UtcInstant $before,int $limit): array;
    /** Atomically dedup operation/hash and enforce the shared tenant/profile/window technical quota. */
    public function existingWarm(WarmIntent $intent): ?WarmState;
    public function beginWarm(WarmIntent $intent,WarmPolicy $policy): WarmState;
    public function sent(WarmIntent $intent,Ref $reservation): void;
    /** Atomic descriptor + canonical usage retry checkpoint. */
    public function finishWarm(WarmIntent $intent,?Descriptor $descriptor,string $usageJson,string $status): void;
    public function accounted(WarmIntent $intent): void;
    public function nextWarm(): ?WarmState;
}
