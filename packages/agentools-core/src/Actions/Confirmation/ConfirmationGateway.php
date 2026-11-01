<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Confirmation;

use AgenToolsPlatform\Core\Actions\ActionSubmission;

/** No required/allow/GateDecision/evidence inputs; trusted ports own those decisions. */
interface ConfirmationGateway
{
    public function prepare(ActionSubmission $submission): Preview;
    public function approve(string $token, ActionSubmission $submission): void;
    public function commit(string $token, ActionSubmission $submission): ConfirmedAction;
}
