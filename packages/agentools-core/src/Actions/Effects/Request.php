<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Effects;

use AgenToolsPlatform\Core\Common\{ActionId, PayloadHash, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** The committed request is passed unchanged to the owner, including its authorization expectation. */
final readonly class Request
{
    public function __construct(public ActionId $action, public string $effectKey,
        public string $ownerNamespace, public string $operationKey, public PayloadHash $requestHash,
        public Ref $requestRef, public ExecutionContext $context, public int $requestedEpoch,
        public Strategy $strategy)
    {
        $action->tenant->assertSame($requestRef->tenant);
        $action->tenant->assertSame($context->identity->tenant);
        foreach ([$effectKey, $ownerNamespace, $operationKey] as $value) {
            if ($value === '' || strlen($value) > 512) { throw new \InvalidArgumentException('Bounded effect identity required'); }
        }
        if ($requestedEpoch < 1) { throw new \InvalidArgumentException('Positive effect epoch required'); }
    }
}
