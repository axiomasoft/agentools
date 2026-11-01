<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Operations;

use AgenTools\Effects\EffectReconciliationRequired;
use AgenToolsPlatform\Core\Actions\{ActionApplication, ActionPolicy, ActionRegistry, ActionSubmission, CancelRequest};
use AgenToolsPlatform\Core\Actions\Cancellation\{Operation, OutputControl};
use AgenToolsPlatform\Core\Actions\Confirmation\{ConfirmationGateway, Preview};
use AgenToolsPlatform\Core\Actions\Effects\{ManualAuthority, ManualEvidence};
use AgenToolsPlatform\Core\Common\{ActionId, CanonicalHasher, ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Confirmation\Journal;
use AgenToolsPlatform\Postgres\Effects\Service as Effects;
use AgenToolsPlatform\Postgres\Operations\{Audit, RunCancellation};

/** Framework-neutral operational composition for the Laravel host. No HTTP/CLI registration or effect loop. */
final readonly class Operations
{
    public function __construct(private Audit $audit, private ActionApplication $actions,
        private ActionRegistry $registry, private ActionPolicy $policy, private ConfirmationGateway $confirmation,
        private ?OutputControl $output = null) {}

    /** @return array<string,mixed> */
    public function cancelRun(Operation $command): array { return (new RunCancellation($this->audit))->apply($command); }

    /** @return array<string,mixed>|null */
    public function status(Operation $command): ?array { return $this->audit->status($command); }

    /** @return array<string,mixed> */
    public function cancelAction(Operation $command): array
    {
        if ($command->kind!=='action_cancel' || !$command->target instanceof ActionId) { throw new \InvalidArgumentException('Action cancellation required'); }
        $saved=$this->audit->begin($command); if ($saved!==null) { return $saved; }
        $this->actions->status($command->target,$command->context);
        $state=(new Journal($this->audit->environment))->read($command->target);
        if ($state->epoch!==$command->expectedEpoch) { throw new ContractViolation(ErrorCode::StaleClaim); }
        $view=$this->actions->cancel($command->target,new CancelRequest($command->id,$command->expectedVersion,$command->context))->view;
        return $this->audit->complete($command,['action_id'=>$view->receipt->actionId->value,'status'=>$view->receipt->wireStatus,
            'version'=>$view->receipt->version,'owner_outcome'=>$view->outcome()?->value]);
    }

    /** @return array<string,mixed> */
    public function interrupt(Operation $command): array
    {
        if (!in_array($command->kind,['stream_stop','tts_stop'],true) || $this->output===null) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        $saved=$this->audit->begin($command); if ($saved!==null) { return $saved; }
        // Durable operation intent precedes transport IO. A crash reuses the same key/hash.
        $this->audit->authorize($command);
        $result=$this->output->stop($command);
        $command->id->tenant->assertSame($result->output->tenant);
        if ($result->output->value!==$command->target->value || $result->channel!==($command->kind==='tts_stop'?'tts':'stream')) {
            throw new IntegrityConflict('Output interruption binding conflict');
        }
        return $this->audit->complete($command,$result->toArray());
    }

    /** @return array<string,mixed> */
    public function resolve(Operation $command, Effects $effects, ManualEvidence $evidence, ManualAuthority $authority): array
    {
        if ($command->kind!=='manual_resolve' || !$command->target instanceof ActionId
            || $command->context->identity->actorRef->value!==$evidence->operator->value
            || $command->evidence?->value!==$evidence->evidence->value
            || $command->payloadHash->value!==self::resolutionHash($evidence,$this->audit->environment->hasher)->value) {
            throw new IntegrityConflict('Manual operator/evidence binding conflict');
        }
        $command->id->tenant->assertSame($evidence->operator->tenant);
        $saved=$this->audit->begin($command); if ($saved!==null) { return $saved; }
        $effects->assertTarget($command->target,$command->expectedEpoch);
        $state=(new Journal($this->audit->environment))->read($command->target);
        if ($state->epoch!==$command->expectedEpoch) { throw new ContractViolation(ErrorCode::StaleClaim); }
        $requestResult=['action_id'=>$command->target->value,'outcome'=>$evidence->outcome->status->value,
            'receipt_ref'=>$evidence->outcome->receipt?->value];
        ksort($requestResult);
        try {
            $effects->resolve($evidence,$authority,$command->expectedVersion,$command->id,$command->reason,
                function () use ($command,$requestResult): void { $this->audit->completeInside($command,$requestResult); });
        } catch (ContractViolation $error) {
            // A concurrent identical operation may have committed while this caller waited.
            $winner=$this->audit->begin($command); if ($winner!==null) { return $winner; }
            throw $error;
        }
        return $requestResult;
    }

    public static function resolutionHash(ManualEvidence $evidence, CanonicalHasher $hasher): PayloadHash
    {
        return $hasher->hash($evidence->operator->tenant,'manual-resolution-input',[
            'operator'=>$evidence->operator->toArray(),'evidence'=>$evidence->evidence->toArray(),'basis'=>$evidence->basis,
            'outcome'=>$evidence->outcome->status->value,'owner'=>$evidence->outcome->ownerNamespace,
            'operation'=>$evidence->outcome->operation->toArray(),'request_hash'=>$evidence->outcome->requestHash->value,
            'receipt'=>$evidence->outcome->receipt?->toArray(),
        ]);
    }

    /** @return array<string,mixed> */
    public function compensationStatus(ActionId $original, ExecutionContext $context): array
    {
        $view=$this->actions->status($original,$context);
        $definition=$this->registry->executionView($view->definition,$this->policy->current($context))->definition;
        if ($definition->compensation===null) { return ['available'=>false,'reason'=>'not_declared']; }
        $this->registry->executionView($definition->compensation,$this->policy->current($context));
        return ['available'=>true,'definition'=>$definition->compensation->toArray()];
    }

    public function prepareCompensation(Operation $command, Ref $originalEffect, #[\SensitiveParameter] ActionSubmission $submission): Preview
    {
        if ($command->kind!=='compensation_prepare' || !$command->target instanceof ActionId) { throw new \InvalidArgumentException('Explicit compensation operation required'); }
        $command->id->tenant->assertSame($originalEffect->tenant);
        $hash=$this->audit->environment->hasher->hash($command->id->tenant,'compensation-input',[$originalEffect->toArray(),$submission->request->requestHash->value]);
        $hash->assertSame($command->payloadHash);
        $this->audit->begin($command);
        $view=$this->actions->status($command->target,$command->context);
        $state=(new Journal($this->audit->environment))->read($command->target);
        if ($view->receipt->version!==$command->expectedVersion || $state->epoch!==$command->expectedEpoch) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
        $effect=$state->effects[$originalEffect->value]??null;
        if ($effect?->status!==\AgenTools\Action\EffectStatus::Confirmed || $view->outcome()?->value!=='succeeded') { throw new EffectReconciliationRequired('Compensation needs a known original owner outcome.'); }
        $definition=$this->registry->executionView($view->definition,$this->policy->current($command->context))->definition;
        if ($definition->compensation===null || $definition->compensation->toArray()!==$submission->request->definition->toArray()) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        $compensating=$this->registry->executionView($definition->compensation,$this->policy->current($submission->request->context))->definition;
        if (!$compensating->requiresConfirmation || $submission->request->originRef->value!=='compensation:'.substr($originalEffect->value,8)
            || $submission->request->idempotencyKey->value===$effect->idempotencyKey
            || $submission->request->context->scopeHash($this->audit->environment->hasher)->value!==$command->context->scopeHash($this->audit->environment->hasher)->value) {
            throw new ContractViolation(ErrorCode::ConfirmationInvalid);
        }
        // Prepare only. Approval, commit and owner execution use the ordinary fresh confirmation path.
        $preview=$this->confirmation->prepare($submission);
        if ($preview->binding->action->value===$command->target->value) { throw new IntegrityConflict('Compensation must be a distinct Action'); }
        $this->audit->complete($command,['action_id'=>$preview->binding->action->value,'proposal_ref'=>$preview->proposal->value,
            'original_action'=>$command->target->value,'original_effect'=>$originalEffect->value]);
        return $preview;
    }
}
