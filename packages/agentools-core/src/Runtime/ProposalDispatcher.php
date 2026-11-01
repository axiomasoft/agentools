<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Actions\ActionView;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Bridge to Actions: atomic same-key/hash acceptance of the SAVED Action identity. No effect loop.
 * Implementations delegate ActionApplication/durable dated lifecycle, including current guards.
 */
interface ProposalDispatcher
{
    public function status(ExecutionContext $context, SavedProposal $proposal): ActionView;
    public function submit(ExecutionContext $context, SavedProposal $proposal): ActionView;
}
