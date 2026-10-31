<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Common\{ActionId, ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Security\{ExecutionContext, HostAuthorization};

/** Headless facade. Effects, journals, confirmation tokens and atomic dedup belong to the adapter. */
final readonly class ActionApplication
{
    public function __construct(
        private ActionRegistry $registry,
        private ActionPolicy $policy,
        private HostAuthorization $host,
        private InputSchemaValidator $schemas,
        private ActionLifecycle $lifecycle,
    ) {
    }

    public function submit(ActionSubmission $submission): ActionReceipt
    {
        $request = $submission->request;
        $request->context->assertCurrent($this->host);
        $entry = $this->registry->executionView($request->definition, $this->policy->current($request->context));
        $this->schemas->assertValid($entry->definition->inputSchema, $submission->inputJson);
        $view = $this->lifecycle->submit($submission, $entry);
        $this->assertView($view, $view->receipt->actionId, $request->context);
        if ($view->definition->toArray() !== $request->definition->toArray()) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict);
        }
        return new \AgenToolsPlatform\Core\Actions\ActionReceipt($view);
    }

    public function status(ActionId $id, ExecutionContext $context): ActionView
    {
        $context->identity->tenant->assertSame($id->tenant);
        $context->assertCurrent($this->host);
        $view = $this->lifecycle->status($id, $context);
        $this->assertView($view, $id, $context);
        return $view;
    }

    public function cancel(ActionId $id, CancelRequest $request): CancelReceipt
    {
        $before = $this->status($id, $request->context);
        $view = $this->lifecycle->cancel($id, $request);
        $this->assertView($view, $id, $request->context);
        $this->assertPreserved($before, $view);
        return new \AgenToolsPlatform\Core\Actions\CancelReceipt($request->commandId, $view);
    }

    public function reconcile(ActionId $id, ReconcileRequest $request): ReconcileReceipt
    {
        $before = $this->status($id, $request->context);
        $view = $this->lifecycle->reconcile($id, $request);
        $this->assertView($view, $id, $request->context);
        $this->assertPreserved($before, $view);
        return new \AgenToolsPlatform\Core\Actions\ReconcileReceipt($request->commandId, $view);
    }

    private function assertPreserved(ActionView $before, ActionView $after): void
    {
        if ($before->definition->toArray() !== $after->definition->toArray()
            || (in_array($before->receipt->wireStatus, ['completed', 'rejected', 'expired', 'failed', 'cancelled'], true)
                && ($before->receipt->toArray() !== $after->receipt->toArray() || $before->resultRef != $after->resultRef))) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict);
        }
    }

    private function assertView(ActionView $view, ActionId $id, ExecutionContext $context): void
    {
        $context->identity->tenant->assertSame($view->receipt->actionId->tenant);
        if ($id->value !== $view->receipt->actionId->value) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict);
        }
        $this->registry->executionView($view->definition, $this->policy->current($context));
    }
}
