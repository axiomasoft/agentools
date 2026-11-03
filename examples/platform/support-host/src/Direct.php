<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Examples\SupportHost;

use AgenToolsPlatform\Core\Actions\ActionSubmission;
use AgenToolsPlatform\Core\Actions\Confirmation\{ConfirmedAction, Preview};
use AgenToolsPlatform\Examples\ConfirmedWrite\{Composition, Owner};
use AgenToolsPlatform\Laravel\Host\{Boundary, Envelope};
use AgenToolsPlatform\Laravel\Managed\Input;

/** Direct Action path: only trusted host UI may populate the confirmation authority. */
final readonly class Direct
{
    private Boundary $boundary;
    private Envelope $envelope;
    public function __construct(Host $host, public Composition $actions, public Owner $owner)
    {
        $host->tenant->assertSame($actions->host->tenant);
        $this->boundary = new Boundary($host, $host, $host->hasher);
        $this->envelope = $this->boundary->open(new Input('ticket-request'));
        if ($this->envelope->input->context->identity->actorRef->value !== $actions->host->actor) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(\AgenToolsPlatform\Core\Common\ErrorCode::AccessDenied);
        }
    }
    public function prepare(ActionSubmission $submission): Preview { $this->current(); return $this->actions->confirmation->prepare($submission); }
    public function approve(Preview $preview, ActionSubmission $submission): void { $this->current(); $this->actions->confirmation->approve($preview->token, $submission); }
    public function commit(Preview $preview, ActionSubmission $submission): ConfirmedAction { $this->current(); return $this->actions->confirmation->commit($preview->token, $submission); }
    public function execute(ConfirmedAction $grant, ActionSubmission $submission): void { $this->current(); $this->owner->write($grant, $submission); }
    private function current(): void
    {
        $this->boundary->assertCurrent($this->envelope);
        if ($this->envelope->input->context->identity->actorRef->value !== $this->actions->host->actor) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(\AgenToolsPlatform\Core\Common\ErrorCode::AccessDenied);
        }
    }
}
