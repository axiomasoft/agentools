<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,ContractViolation,ErrorCode,IntegrityConflict,Ref};
use AgenToolsPlatform\Core\Models\{Deployment,ModelRequest,ModelResponse,ModelTransport,TransportPreflight};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;
use AgenToolsPlatform\Core\Security\{BoundaryGuard,BoundaryStage,EntryPoint,ExecutionContext,SnapshotEvidence,ToolPin};

/** Per-invocation adapter around the existing single model exchange; checks immediately before provider IO. */
final readonly class GuardedTransport implements \AgenToolsPlatform\Core\Models\ModelTransport
{
    /** @param array<string,DefinitionRef> $definitions */
    public function __construct(private ModelTransport $inner,private ExecutionContext $context,private SnapshotEvidence $snapshot,
        private EntryPoint $entry,private Deployment $selected,private Ref $credential,private array $definitions,private BoundaryGuard $guard,
        private RequestRenderer $renderer,private CanonicalHasher $hasher) { $context->session->tenant->assertSame($credential->tenant); }
    public function generate(ModelRequest $request,Deployment $deployment): ModelResponse
    { $this->check($request,$deployment,false);return $this->inner->generate($request,$deployment); }
    public function stream(ModelRequest $request,Deployment $deployment): iterable
    { $this->check($request,$deployment,true);yield from $this->inner->stream($request,$deployment); }

    /** Runtime-only one-exchange boundary. Journal IO is outside both exception classifiers. */
    public function exchange(InvocationPlan $plan, InvocationJournal $journal, ExecutionContext $context,
        InvocationCheckpoint &$saved): ExchangeResult|InvocationRefusal
    {
        $refusal = $this->refusal($plan->request, $plan->deployment);
        if ($refusal !== null) { return $refusal; }
        $saved = $journal->outbound($context, $plan, $saved);
        // Recheck after the durable intent: policy, rendering or deadline may have changed during commit.
        $refusal = $this->refusal($plan->request, $plan->deployment);
        if ($refusal !== null) { return $refusal; }
        try {
            return ExchangeResult::response($plan, $this->inner->generate($plan->request, $plan->deployment), $this->hasher);
        } catch (\Throwable) {
            // Even a typed denial thrown by the provider is not proof that its request was never sent.
            return ExchangeResult::unknown($plan, $this->hasher);
        }
    }

    private function refusal(ModelRequest $request, Deployment $deployment): ?InvocationRefusal
    {
        try { $this->check($request, $deployment, false); }
        catch (ContractViolation|IntegrityConflict $error) { return InvocationRefusal::denied($error); }
        return null;
    }

    private function check(ModelRequest $request,Deployment $deployment,bool $stream): void
    {
        $this->context->session->tenant->assertSame($request->invocationId->tenant);$this->credential->assertSame($request->credentialRef);$this->snapshot->ref()->assertSame($request->contextSnapshotRef);
        $this->snapshot->hash()->assertSame($request->contextSnapshotHash);$this->snapshot->deployment()->assertSame($deployment->ref);$this->selected->ref->assertSame($deployment->ref);
        if ([$deployment->provider,$deployment->account,$deployment->region,$deployment->model]!==[$this->selected->provider,$this->selected->account,$this->selected->region,$this->selected->model]
            || $this->renderer->version()!==$this->snapshot->rendererVersion()) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext); }
        $tools=[];foreach ($request->tools as $tool) {
            $definition=$this->definitions[$tool->name]??throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied);
            $tools[]=new \AgenToolsPlatform\Core\Security\ToolPin($tool->name,$definition->id,$definition->version,$definition->hash,$this->hasher->hash($request->invocationId->tenant,'guard-tool-schema',json_decode(json_encode($tool,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR)));
        }
        $actual=$this->renderer->renderRequest($request,$deployment);$this->snapshot->renderedHash()->assertSame($this->hasher->hash($request->invocationId->tenant,'context-rendered',$actual));
        $this->guard->check($this->context,$this->snapshot,$this->entry,BoundaryStage::Invocation,$tools);
        if ($this->renderer->version()!==$this->snapshot->rendererVersion()) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext); }
        $this->snapshot->renderedHash()->assertSame($this->hasher->hash($request->invocationId->tenant,'context-rendered',$this->renderer->renderRequest($request,$deployment)));
        TransportPreflight::check($request,$deployment,$this->guard->now(),$stream);
    }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return ['snapshot_ref'=>$this->snapshot->ref()->value,'snapshot_hash'=>$this->snapshot->hash()->value,'entry'=>$this->entry->value]; }
}
