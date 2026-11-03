<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ChatomBridge;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, Ref};
use AgenToolsPlatform\Core\Context\History\{Derivations, MessageRef};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Delivery\DeliveryRepository;

/** Idempotent consumer of the OWNER's committed durable edit/delete/purge event.
 * ACK only after every step. Owner event retry/scanner recovers partial progress.
 */
final readonly class Invalidation
{
    /** @param \Closure(ExecutionContext,Ref,MessageRef,string):void $authorize Exact owner event/source mapping and cleanup authority. */
    public function __construct(private DeliveryRepository $deliveries, private Derivations $derivations,
        private CanonicalHasher $hasher, private \Closure $authorize) {}
    public function apply(ExecutionContext $context, Ref $source, MessageRef $message, int $watermark,
        string $event, string $reason): void
    {
        $context->session->tenant->assertSame($source->tenant); $source->tenant->assertSame($message->ref->tenant);
        if (!in_array($reason, ['source_deleted', 'source_corrected', 'source_revoked', 'consent_revoked'], true)
            || $event === '' || strlen($event) > 128 || $watermark < 1) { throw new \InvalidArgumentException('Committed owner event required'); }
        ($this->authorize)($context, $source, $message, $reason);
        $hash = $this->hasher->hash($source->tenant, 'chatom-source-event', [$source->toArray(), $message->toArray(), $watermark, $reason]);
        $this->deliveries->closeSource($context, $source, $watermark, $event, $hash);
        $this->derivations->invalidate($context, $message, $reason);
    }
}
