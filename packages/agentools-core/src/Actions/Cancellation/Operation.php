<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Cancellation;

use AgenToolsPlatform\Core\Common\{ActionId, CanonicalHasher, PayloadHash, Ref, RunId};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Immutable authenticated command. Actor/scope come from the host context, never an operator form. */
final readonly class Operation
{
    public PayloadHash $hash;

    public function __construct(public Ref $id, public string $kind, public ActionId|RunId|Ref $target,
        public int $expectedVersion, public int $expectedEpoch, public string $reason,
        public ?Ref $evidence, public PayloadHash $payloadHash, public ExecutionContext $context, CanonicalHasher $hasher)
    {
        foreach ([$target,$context->identity->actorRef,$context->session,$evidence] as $ref) {
            if ($ref !== null) { $id->tenant->assertSame($ref->tenant); }
        }
        $expected = match ($kind) {
            'run_cancel','run_finalize'=> $target instanceof RunId,
            'action_cancel','manual_resolve','compensation_prepare'=> $target instanceof ActionId,
            'stream_stop','tts_stop'=> $target instanceof Ref,
            default=> false,
        };
        if (!$expected || $expectedVersion<0 || $expectedEpoch<0 || max($expectedVersion,$expectedEpoch)>9007199254740991
            || preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D',$reason)!==1
            || (in_array($kind,['run_finalize','manual_resolve'],true) && $evidence===null)) {
            throw new \InvalidArgumentException('Invalid operational command or evidence');
        }
        $this->hash=$hasher->hash($id->tenant,'operator-operation',[
            'id'=>$id->toArray(),'kind'=>$kind,'target'=>$target->toArray(),'expected_version'=>$expectedVersion,
            'expected_epoch'=>$expectedEpoch,'reason'=>$reason,'evidence'=>$evidence?->toArray(),
            'payload_hash'=>$payloadHash->value,'actor'=>$context->identity->actorRef->toArray(),
            'scope_hash'=>$context->scopeHash($hasher)->value,
        ]);
    }
}
