<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

/** Durable same-key owner port. Reauthorize at actual IO; response generation is never a receipt. */
interface DisclosureDelivery { public function deliver(ExecutionContext $context,DisclosurePayload $payload): DeliveryObservation; }
