<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

use AgenToolsPlatform\Core\Common\{EffectId, OperationReceipt, PayloadHash, Ref};

/** Missing lookup and unknown outcome are separate, neither authorizes a write retry. */
final class ScriptedOwnerLookup
{
    /** @param list<OwnerLookupStep> $script */
    public function __construct(private array $script) {}
    public function lookup(EffectId $id, Ref $savedKey, PayloadHash $request): ?OperationReceipt
    {
        $step = $this->script[0] ?? throw new \UnderflowException('Owner lookup script exhausted');
        $step->effectId->assertSame($id);
        $step->savedOperationKey->assertSame($savedKey);
        $step->requestHash->assertSame($request);
        array_shift($this->script);
        return $step->reply;
    }
    public function assertConsumed(): void
    {
        if ($this->script !== []) { throw new \LogicException('Unused owner lookup'); }
    }
}
