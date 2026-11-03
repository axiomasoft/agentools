<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, InstanceId, PayloadHash, Ref, RunId, TurnId};
use AgenToolsPlatform\Core\Security\{DisclosurePolicy, ExecutionContext};

/** Existing runtime response, not a new invocation or a second transcript owner. */
final readonly class Response
{
    public Ref $key;
    public PayloadHash $hash;
    public PayloadHash $scope;
    public function __construct(public ExecutionContext $context, public InstanceId $assistant,
        public TurnId $turn, public RunId $run, public Ref $response, public int $version,
        public Ref $destination, public int $contextRevision, public DisclosurePolicy $policy, CanonicalHasher $hasher)
    {
        foreach ([$assistant, $turn, $run, $response, $destination, $policy->ref] as $ref) {
            $context->session->tenant->assertSame($ref->tenant);
        }
        if (min($version,$contextRevision)<1 || max($version,$contextRevision)>9007199254740991) {
            throw new \InvalidArgumentException('Bounded response/context version required');
        }
        $this->scope=$context->scopeHash($hasher);
        $this->key = new Ref($response->tenant,'voice-response:'.substr($hasher->hash($response->tenant,'voice-delivery-key',
            [$assistant->toArray(),$turn->toArray(),$version,$destination->toArray()])->value,8));
        $this->hash = $hasher->hash($response->tenant,'voice-response',$this->manifest());
    }

    /** @return array<string,mixed> */
    public function manifest(): array
    {
        return ['assistant'=>$this->assistant->toArray(),'session'=>$this->context->session->toArray(),
            'actor'=>$this->context->identity->actorRef->toArray(),'turn'=>$this->turn->toArray(),'run'=>$this->run->toArray(),
            'response'=>$this->response->toArray(),'version'=>$this->version,'destination'=>$this->destination->toArray(),
            'context_revision'=>$this->contextRevision,'policy'=>$this->policy->toArray(),
            'scope_hash'=>$this->scope->value];
    }
}
