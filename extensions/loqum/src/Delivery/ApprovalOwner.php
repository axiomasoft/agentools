<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Actions\ActionSubmission;
use AgenToolsPlatform\Core\Actions\Confirmation\Preview;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Security\{ExecutionContext, HostAuthorization};
use AgenToolsPlatform\Loqum\Ingress\MediaState;

interface ApprovalOwner extends HostAuthorization
{
    public function presentation(ActionSubmission $submission): string;
    public function context(Preview $preview): ExecutionContext;
    /** Only an authenticated affirmative event bound to this exact preview; transcript-only returns null. */
    public function event(Preview $preview): ?ApprovalEvent;
    public function media(ExecutionContext $context): MediaState;
    /** Resolve an immutable complete preview display/playout receipt and verify its exact presentation hash. */
    public function assertPresented(Preview $preview,ApprovalEvent $event): void;
    public function now(): UtcInstant;
}
