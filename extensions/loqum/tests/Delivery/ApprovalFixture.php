<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Tests\Delivery;

use AgenToolsPlatform\Core\Actions\ActionSubmission;
use AgenToolsPlatform\Core\Actions\Confirmation\Preview;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, SessionId, UtcInstant};
use AgenToolsPlatform\Core\Security\{ExecutionContext, HostIdentity};
use AgenToolsPlatform\Loqum\Delivery\{ApprovalEvent, ApprovalOwner};
use AgenToolsPlatform\Loqum\Ingress\MediaState;
use AgenToolsPlatform\Tests\Confirmation\Fixture;

final class ApprovalFixture implements ApprovalOwner
{
    public ?ApprovalEvent $approval=null;
    public bool $presented=true;
    public int $epoch=1;
    public int $revision=1;
    public function __construct(public Fixture $f) {}
    public function authorize(SessionId $session,string $purpose): HostIdentity { return $this->f->composition->host->authorize($session,$purpose); }
    public function presentation(ActionSubmission $submission): string { return $this->f->composition->host->presentation($submission); }
    public function context(Preview $preview): ExecutionContext { return $this->f->composition->context(); }
    public function event(Preview $preview): ?ApprovalEvent { return $this->approval; }
    public function media(ExecutionContext $context): MediaState { return new MediaState(new Ref($context->session->tenant,'media'),$this->epoch,$this->revision); }
    public function assertPresented(Preview $preview,ApprovalEvent $event): void
    {
        if (!$this->presented) { throw new ContractViolation(ErrorCode::ConfirmationInvalid); }
    }
    public function now(): UtcInstant { return new UtcInstant($this->f->composition->host->now()->format('Y-m-d\TH:i:s\Z')); }
    public function bind(Preview $p,array $changes=[]): void
    {
        $c=$this->f->composition;$ctx=$c->context();
        $args=['source'=>new Ref($ctx->session->tenant,'host:voice-event'),'session'=>$ctx->session,'actor'=>$ctx->identity->actorRef,
            'proposal'=>$p->proposal,'bindingHash'=>$p->binding->hash,'previewHash'=>$p->binding->previewHash,
            'inputHash'=>$c->submission()->request->inputHash,'policyRevision'=>$ctx->identity->policyRevision,'mediaEpoch'=>1,'contextRevision'=>1,
            'presentationReceipt'=>new Ref($ctx->session->tenant,'host:complete-preview-receipt'),'expiresAt'=>$p->binding->expiresAt];
        $this->approval=new ApprovalEvent(...array_replace($args,$changes));
    }
}
