<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

use AgenToolsPlatform\Core\Actions\{ActionApplication, ActionPolicy, ActionRegistry, ActionRequest, ActionSubmission, ActionView};
use AgenToolsPlatform\Core\Actions\Confirmation\ConfirmationGateway;
use AgenToolsPlatform\Core\Common\{CanonicalHasher, CanonicalJson, PayloadHash, Ref};
use AgenToolsPlatform\Core\Runtime\{InvocationPayloads, ProposalDispatcher, SavedProposal};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** No handler invocation here. All proposals enter the existing durable Actions/confirmation boundary. */
final readonly class DurableProposals implements ProposalDispatcher
{
    public function __construct(private ActionApplication $actions, private ConfirmationGateway $confirmation,
        private ActionRegistry $registry, private ActionPolicy $policy, private CanonicalHasher $hasher,
        private CanonicalJson $json, private Scope $scope, private InvocationPayloads $payloads) {}

    public function submit(ExecutionContext $context, SavedProposal $proposal): ActionView
    {
        $this->scope->assertActive();
        $definition = $this->registry->executionView($proposal->definition, $this->policy->current($context))->definition;
        $tenant = $context->identity->tenant;
        $submission = new ActionSubmission(new ActionRequest($proposal->definition, new Ref($tenant, $proposal->id->value),
            $proposal->id, new Ref($tenant, $proposal->id->value), new Ref($tenant, 'proposal-input:'.$proposal->id->value),
            $proposal->inputHash, $context, $proposal->deadline, $this->hasher), $proposal->argumentsJson, $this->hasher, $this->json);
        $this->payloads->put($context, $submission->request->inputRef,
            new PayloadHash('sha-256:'.hash('sha256', $submission->inputJson)), $submission->inputJson);
        if ($definition->requiresConfirmation) { $this->confirmation->prepare($submission); }
        else { $this->actions->submit($submission); }
        return $this->status($context, $proposal);
    }

    public function status(ExecutionContext $context, SavedProposal $proposal): ActionView
    {
        $this->scope->assertActive();
        return $this->actions->status($proposal->actionId, $context);
    }
}
