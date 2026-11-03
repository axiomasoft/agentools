<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ChatomBridge;

use AgenToolsPlatform\Core\Common\{ContractViolation, IntegrityConflict, Outcome, PayloadHash};
use AgenToolsPlatform\Core\Runtime\InvocationPayloads;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Delivery\{DeliveryClaim, DeliveryRepository, DeliverySubmission};

/** One bounded relay step over the EXISTING durable delivery owner. No model/effect dispatcher. */
final readonly class PostgresDelivery implements DeliveryPort
{
    /** @param \Closure(ExecutionContext,string,string):void $audit Host-owned technical cancellation audit. */
    public function __construct(private DeliveryRepository $store, private InvocationPayloads $payloads,
        private Destination $destination, private \Closure $audit, private int $leaseMilliseconds = 30000)
    {
        if ($leaseMilliseconds < 1 || $leaseMilliseconds > 3600000) { throw new \InvalidArgumentException('Bounded lease required'); }
    }
    public function submit(ExecutionContext $context, DeliverySubmission $intent): void
    {
        try { $this->store->submit($context, $intent); }
        catch (IntegrityConflict) { throw new IntegrityConflict('DELIVERY_PAYLOAD_CONFLICT'); }
    }
    public function status(ExecutionContext $context, string $key): array { return $this->store->status($context, $key); }
    public function cancel(ExecutionContext $context, string $key, string $reason): void
    {
        if (!in_array($reason, ['user_cancelled', 'source_revoked', 'policy_changed'], true)) {
            throw new \InvalidArgumentException('Known cancellation reason required');
        }
        $this->store->cancel($context, $key);
        ($this->audit)($context, $key, $reason);
    }
    /** @return list<string> Queue is only a signal; scanner recovers committed work. */
    public function pending(ExecutionContext $context, int $limit = 100): array { return $this->store->pending($context, $limit); }

    /** @return array{state:string,cancelled:bool,receipt:?string,version:int} */
    public function relay(ExecutionContext $context, string $key): array
    {
        $claim = $this->store->claim($context, $key, $this->leaseMilliseconds);
        if ($claim === null) { return $this->store->status($context, $key); }
        $intent = $claim->submission;
        try {
            $this->destination->authorize($context, $intent);
            $receipt = $this->destination->lookup($context, $intent);
            $receipt->assertSubmission($intent);
            if ($receipt->outcome === Outcome::Unknown) {
                $current = $this->store->prepareCommit($context, $intent, $claim->token());
                $bytes = $this->payloads->get($context, $intent->payload);
                $intent->hash->assertSame(new PayloadHash('sha-256:'.hash('sha256', $bytes)));
                $this->store->assertCurrent($context, $intent, $claim->token());
                $this->destination->authorize($context, $intent);
                $receipt = $this->destination->send($context, $intent, $bytes, $current);
                $receipt->assertSubmission($intent);
            }
            $this->store->settle($context, $claim, match ($receipt->outcome) {
                Outcome::Succeeded => 'success', Outcome::Failed => 'failed', Outcome::Unknown => 'unknown',
            }, $receipt->message);
        } catch (ContractViolation|IntegrityConflict $error) {
            $this->unknown($context, $claim);
            throw $error;
        } catch (\Throwable) {
            // Remote commit or local receipt may have happened. Preserve same key for lookup.
            $this->unknown($context, $claim);
        }
        return $this->store->status($context, $key);
    }
    private function unknown(ExecutionContext $context, DeliveryClaim $claim): void
    {
        try { $this->store->settle($context, $claim, 'unknown'); }
        catch (\Throwable) { /* Existing sending intent stays unknown across lease expiry/restart. */ }
    }
}
