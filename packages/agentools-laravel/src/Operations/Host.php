<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Operations;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Postgres\Delivery\DeliveryClaim;
use AgenToolsPlatform\Laravel\Managed\ProductionBinding;

/** Host-owned ports, never automatically installed or substituted with testing adapters. */
interface Host extends ProductionBinding
{
    public function authority(): Authority;
    /** @return list<Finding> Must inspect actual installed bindings/registry/credentials/evidence/queue policy. */
    public function diagnose(Command $command): array;
    /** Publish a repeatable wake for this existing Run only. Receiver uses its normal claim/epoch path. */
    public function wake(Command $command, string $run): void;
    /** Lookup/reconcile an original provider/effect/proposal identity through its existing journal/owner.
     * No effect replay or new Invocation here. Return null when unsupported/unresolved.
     * @param \Closure():void $reauthorize Recheck at every owner IO/commit boundary.
     */
    public function reconcile(Command $command, Observation $observation, \Closure $reauthorize): ?Ref;
    /** Same destination key/hash, destination lookup/dedup required after an unknown ACK.
     * @param \Closure():void $reauthorize Recheck immediately before disclosure/destination commit.
     */
    public function deliver(Command $command, DeliveryClaim $claim, \Closure $reauthorize): ?Ref;
}
