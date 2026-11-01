<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,ContractViolation,ErrorCode,Ref};

/** Per-response transient stream; never a user-state singleton or a second model loop. */
final class DisclosureStream
{
    private DisclosurePolicy $policy;private string $buffer='';private int $sequence=1;private int $ordinal=1;private int $nextDelivery=1;
    private bool $closed=false;private bool $stopped=false;private int $generated=0;
    /** @var array<string,DisclosurePayload> */ private array $pending=[];
    /** @var array<string,DeliveryObservation> */ private array $receipts=[];
    public function __construct(private readonly ExecutionContext $context,private readonly SnapshotEvidence $snapshot,
        private readonly EntryPoint $entry,private readonly Ref $invocation,private readonly Ref $response,private readonly int $responseVersion,
        private readonly BoundaryGuard $guard,private readonly DisclosureAuthority $authority,private readonly DisclosureInspector $inspector,private readonly CanonicalHasher $hasher)
    {
        foreach ([$invocation,$response] as $r) { $context->session->tenant->assertSame($r->tenant); }
        if ($responseVersion<1 || $responseVersion>9007199254740991) { throw new \InvalidArgumentException('Stable response version required'); }
        $guard->check($context,$snapshot,$entry,BoundaryStage::Disclosure);$this->policy=$authority->policy($context,$snapshot,$response);$this->validate($this->policy);
    }
    public function push(GeneratedChunk $chunk): ?DisclosurePayload
    {
        $this->invocation->assertSame($chunk->invocation);$this->response->assertSame($chunk->response);
        if ($this->closed || $this->stopped || $chunk->sequence!==$this->sequence) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict); }
        if ($this->generated+strlen($chunk->bytes)>$this->policy->maxBytes) { $this->stop();throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AdmissionDenied); }
        ++$this->sequence;$this->generated+=strlen($chunk->bytes);$this->buffer.=$chunk->bytes;$this->closed=$chunk->responseEnd;
        $ready=match($this->policy->mode) { DisclosureMode::Buffered=>$chunk->responseEnd,DisclosureMode::CheckedSegments=>$chunk->segmentEnd,DisclosureMode::Live=>true };
        if (!$ready) { return null; }
        try {
            $this->fresh();$bytes=$this->buffer;$hash=$this->hasher->hash($this->response->tenant,'disclosure-payload',$bytes);$check=null;
            $prechecked=$this->policy->mode!==DisclosureMode::Live;
            if ($prechecked) {
                $check=$this->inspector->check($this->context,$this->response,$bytes,$hash,$chunk->responseEnd);
                $this->context->session->tenant->assertSame($check->ref->tenant);$hash->assertSame($check->hash);
                if ($check->access!==AccessState::Allow || !$check->complete) { $this->stop();throw new \AgenToolsPlatform\Core\Common\ContractViolation($check->access===AccessState::Unknown?ErrorCode::CapabilityUnavailable:ErrorCode::AccessDenied); }
            }
            $this->fresh();
        } catch (\Throwable $failure) { $this->stop();throw $failure; }
        $ref=new \AgenToolsPlatform\Core\Common\Ref($this->response->tenant,'disclosure:'.substr($this->hasher->hash($this->response->tenant,'disclosure-identity',
            [$this->response->value,$this->responseVersion,$this->ordinal])->value,8));
        $payload=new \AgenToolsPlatform\Core\Security\DisclosurePayload($ref,$this->response,$this->responseVersion,$this->ordinal++,$bytes,$hash,$prechecked,$check?->ref,$this->policy->liveApproval);
        $this->buffer='';$this->pending[$ref->value]=$payload;return $payload;
    }
    public function deliver(Ref $ref,DisclosureDelivery $owner): DeliveryObservation
    {
        $this->context->session->tenant->assertSame($ref->tenant);$this->fresh();
        if (isset($this->receipts[$ref->value])) { return $this->receipts[$ref->value]; }
        $payload=$this->pending[$ref->value]??throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied);
        if ($this->stopped || $payload->ordinal!==$this->nextDelivery) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict); }
        try { $receipt=$owner->deliver($this->context,$payload); }
        catch (\AgenToolsPlatform\Core\Common\ContractViolation|\AgenToolsPlatform\Core\Common\IntegrityConflict $failure) { $this->stop();throw $failure; }
        catch (\Throwable) {
            $receipt=new \AgenToolsPlatform\Core\Security\DeliveryObservation(new \AgenToolsPlatform\Core\Common\Ref($ref->tenant,'unknown:'.$ref->value),$ref,$payload->hash,DeliveryState::Unknown,null);
        }
        try {
            $this->context->session->tenant->assertSame($receipt->receipt->tenant);$ref->assertSame($receipt->payload);$payload->hash->assertSame($receipt->hash);
        } catch (\Throwable $failure) { $this->stop();throw $failure; }
        if (($receipt->deliveredBytes!==null && $receipt->deliveredBytes>strlen($payload->bytes)) || $receipt->confirmedPrefixBytes>strlen($payload->bytes)
            || ($receipt->state===DeliveryState::Delivered && $receipt->deliveredBytes!==strlen($payload->bytes))) { $this->stop();throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
        $this->receipts[$ref->value]=$receipt;unset($this->pending[$ref->value]);++$this->nextDelivery;
        if ($receipt->state!==DeliveryState::Delivered) { $this->stop(); }return $receipt;
    }
    public function interrupt(): void { $this->stop(); }
    /** @return array<string,mixed> */
    public function statistics(): array
    {
        $delivered=0;$heard=0;$prefix=0;
        foreach ($this->receipts as $r) { $delivered=$delivered===null||$r->deliveredBytes===null?null:$delivered+$r->deliveredBytes;
            $heard=$heard===null||$r->heardBytes===null?null:$heard+$r->heardBytes;$prefix+=$r->confirmedPrefixBytes; }
        return ['generated_bytes'=>$this->generated,'pending_payloads'=>count($this->pending),'receipts'=>count($this->receipts),
            'delivered_bytes'=>$delivered,'heard_bytes'=>$heard,'confirmed_prefix_bytes'=>$prefix,'stopped'=>$this->stopped];
    }
    private function fresh(): void
    {
        $this->guard->check($this->context,$this->snapshot,$this->entry,BoundaryStage::Disclosure);
        $current=$this->authority->policy($this->context,$this->snapshot,$this->response);$this->validate($current);
        if ($current->toArray()!==$this->policy->toArray()) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext); }
        $this->guard->check($this->context,$this->snapshot,$this->entry,BoundaryStage::Disclosure);
    }
    private function validate(DisclosurePolicy $policy): void
    {
        $this->context->session->tenant->assertSame($policy->ref->tenant);
        if ($this->snapshot->sensitive() && $policy->mode!==DisclosureMode::Buffered) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict); }
    }
    private function stop(): void { $this->stopped=true;$this->closed=true;$this->buffer=''; }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return ['response_ref'=>$this->response->value,'snapshot_ref'=>$this->snapshot->ref()->value,'statistics'=>$this->statistics()]; }
}
