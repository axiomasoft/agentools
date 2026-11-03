<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ChatomBridge;

use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Delivery\DeliverySubmission;

/** One selected native-key relay. Chatom alone owns transcript, visibility and client-key dedup. */
interface Destination
{
    /** Fresh membership/visibility/hold/revoke and single-relay binding; no authority from IDs. */
    public function authorize(ExecutionContext $context, DeliverySubmission $submission): void;
    /** Lookup must compare destination/key/hash and check current read permission. */
    public function lookup(ExecutionContext $context, DeliverySubmission $submission): Receipt;
    /** Atomically recheck owner membership/moderation and invoke $current before native-key mutation.
     * Same tenant/destination/key/hash returns the original message; changed hash MUST conflict.
     * @param \Closure():void $current Same-operation runtime fence check; never queued authority.
     */
    public function send(ExecutionContext $context, DeliverySubmission $submission, string $payload, \Closure $current): Receipt;
}
