<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Required host current permissions, purpose, emergency and consent policy. */
interface HistoryPolicy
{
    public function authorize(ExecutionContext $context,Ref $branch,string $operation): void;
    public function consentVersion(ExecutionContext $context,Ref $branch): string;
    public function acceptsState(ExecutionContext $context,?WorkingState $before,WorkingProposal $proposal): Ref;
    public function sourceAllowed(ExecutionContext $context,MessageRef $source): bool;
}
