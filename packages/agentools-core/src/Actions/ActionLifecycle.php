<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Common\ActionId;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/**
 * Adapter boundary, not an alternate reducer/effect loop. Implementations delegate dated
 * ActionReducer/EventStore, ConfirmationService and IdempotencyCoordinator. Owner business
 * authorization and current confirmation remain mandatory at dispatch, even after facade checks.
 */
interface ActionLifecycle
{
    /** Atomically reserve tenant submission key/hash + pinned definition and durable created intent.
     * Same key/hash returns saved receipt; different hash conflicts. No model/effect in submit. */
    public function submit(ActionSubmission $submission, ActionRegistration $registration): ActionView;

    /** Tenant-scoped, read-authorized, safe metadata only; never starts work. */
    public function status(ActionId $id, ExecutionContext $context): ActionView;

    /** Persist cancellation_requested under CAS. Known success/effect outcomes are immutable. */
    public function cancel(ActionId $id, CancelRequest $request): ActionView;

    /** Status lookup by saved owner operation key; unknown/missing means backoff/manual, no write retry. */
    public function reconcile(ActionId $id, ReconcileRequest $request): ActionView;
}
