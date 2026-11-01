<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

use AgenToolsPlatform\Core\Common\{AcceptedTurn, Ref, UtcInstant};
use AgenToolsPlatform\Core\Security\TrustedInput;

/** Scanner performs durable admission only. The existing outbox wakes the existing managed runtime. */
final readonly class Scanner
{
    public function __construct(private Store $store) {}

    /** @return list<AcceptedTurn> */
    public function scan(Ref $id, ?UtcInstant $through = null): array
    {
        try {
            $context = $this->store->authority->context();
            $batch = $this->store->plan($id,$context,$through ?? $this->store->authority->now());
            $receipts = [];
            foreach ($batch->slots as $planned) {
                $existing = $this->store->accepted($batch->schedule,$planned,$context);
                if ($existing !== null) { $receipts[] = $existing; continue; }
                $this->store->authorize($context,$batch->schedule,'prepare');
                $prepared = $this->store->authority->prepare($context,$batch->schedule,$planned,$this->store->inputRef($batch->schedule,$planned));
                $input = new TrustedInput($context,$this->store->source($batch->schedule,$planned),$prepared->payload);
                $receipts[] = $this->store->accept($batch->schedule->id,$batch->schedule->version,$planned,$input,$prepared->snapshot);
            }
            return $receipts;
        } finally {
            try { $this->store->authority->reset(); }
            catch (\Throwable) { throw new InspectionFailure('authority_reset_failed'); }
        }
    }
}
