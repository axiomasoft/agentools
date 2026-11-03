<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ChatomBridge;

use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Delivery\DeliverySubmission;

/** Additive optional seam. Existing core/delivery identities and dated wire statuses are unchanged. */
interface DeliveryPort
{
    public function submit(ExecutionContext $context, DeliverySubmission $intent): void;
    /** @return array{state:string,cancelled:bool,receipt:?string,version:int} */
    public function status(ExecutionContext $context, string $key): array;
    public function cancel(ExecutionContext $context, string $key, string $reason): void;
}
