<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ChatomBridge;

use AgenToolsPlatform\Core\Common\{AcceptedTurn, Ref};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Security\TrustedInput;
use AgenToolsPlatform\Postgres\Delivery\DeliveryRepository;
use AgenToolsPlatform\Postgres\Inbox\{IntakeRepository, SourceAdmission};
use AgenToolsPlatform\Postgres\Runtime\RepositoryContext;

/** Only the selected committed-source relay calls this. ACK follows atomic inbox/Turn/Run/wake. */
final readonly class Incoming
{
    public function __construct(private RepositoryContext $environment, private DeliveryRepository $deliveries) {}
    public function accept(TrustedInput $input, ContextSnapshot $snapshot, Ref $source, int $revision): AcceptedTurn
    {
        $this->deliveries->bindInput($input->context, $input->payload->inputRef, $source, $revision);
        return new IntakeRepository($this->environment, new SourceAdmission())->accept($input, $snapshot);
    }
}
