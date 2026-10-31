<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Actions;

use AgenTools\Action\{ActionEvent, ActionJournal, ActionReducer, Actor, JournalHashChain};
use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Actions\{ActionLifecycle, ActionRegistration, ActionSubmission, ActionView, CancelRequest, ReconcileRequest};
use AgenToolsPlatform\Core\Common\{ActionId, ActionReceipt, ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Scripted adapter for facade contracts, NOT durable storage or an effect dispatcher. */
final class DatedLifecycle implements ActionLifecycle
{
    public int $submits = 0;
    public int $mutations = 0;
    public int $lookups = 0;
    /** @var array<string, array{hash: string, view: ActionView}> */
    private array $submissions = [];
    public ?ActionView $view = null;
    public ?ActionView $forcedMutation = null;
    public ActionJournal $journal;
    public ActionReducer $reducer;
    public function __construct()
    {
        $this->journal = new ActionJournal();
        $this->reducer = new ActionReducer(hashChain: new JournalHashChain(new Canonicalizer()));
    }

    public function submit(ActionSubmission $submission, ActionRegistration $registration): ActionView
    {
        ++$this->submits;
        $request = $submission->request;
        $key = $request->context->identity->tenant->value . ':' . $request->idempotencyKey->value;
        if (isset($this->submissions[$key])) {
            $saved = $this->submissions[$key];
            if ($saved['hash'] !== $request->requestHash->value) { throw new ContractViolation(ErrorCode::IntegrityConflict); }
            return $saved['view'];
        }
        $id = new ActionId($request->context->identity->tenant, 'dated-action-' . count($this->submissions));
        $this->journal = new ActionJournal();
        $this->append('action_created', $id);
        $view = $this->projection($id, $registration);
        $this->submissions[$key] = ['hash' => $request->requestHash->value, 'view' => $view];
        return $this->view = $view;
    }

    public function status(ActionId $id, ExecutionContext $context): ActionView
    {
        ++$this->lookups;
        return $this->view ?? throw new \LogicException('No scripted action');
    }

    public function cancel(ActionId $id, CancelRequest $request): ActionView
    {
        ++$this->mutations;
        return $this->forcedMutation ?? $this->view ?? throw new \LogicException('No scripted action');
    }

    public function reconcile(ActionId $id, ReconcileRequest $request): ActionView
    {
        ++$this->mutations;
        return $this->forcedMutation ?? $this->view ?? throw new \LogicException('No scripted action');
    }

    /** @param array<string, mixed> $payload */
    public function append(string $kind, ActionId $id, array $payload = [], ?int $epoch = null): void
    {
        $state = $this->journal->state($this->reducer);
        $seq = $state->seq + 1;
        $this->journal = $this->journal->append(new ActionEvent('event-' . $seq, $id->value, $seq, $seq - 1,
            '2026-10-31T12:00:00Z', new Actor('host', 'owner-1'), $kind, 1, '2026-09', $payload, $epoch,
            $state->previousHash), $this->reducer);
    }

    public function projection(ActionId $id, ActionRegistration $registration): ActionView
    {
        $state = $this->journal->state($this->reducer);
        return new ActionView(new ActionReceipt($id, $state->status->value ?? throw new \LogicException('Missing status'), $state->seq),
            $registration->definition->ref, $state->hasUnresolvedEffect() ? 'reconcile' : 'status');
    }
}
