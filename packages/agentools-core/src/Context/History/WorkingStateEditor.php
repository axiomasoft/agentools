<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref,UtcInstant};
use AgenToolsPlatform\Core\Security\ExecutionContext;

final readonly class WorkingStateEditor
{
    public function __construct(private SummaryStore $store,private HistoryPolicy $policy) {}
    public function apply(ExecutionContext $context,WorkingProposal $proposal,int $expectedVersion,UtcInstant $now,UtcInstant $expiresAt,string $retention): WorkingState
    {
        $context->session->tenant->assertSame($proposal->branch->tenant);$this->policy->authorize($context,$proposal->branch,'working_state');
        $before=$this->store->state($context,$proposal->branch);
        if (($before===null?0:$before->version)!==$expectedVersion) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::OptimisticConflict); }
        if (!Checks::future($expiresAt,$now)) { throw new \InvalidArgumentException('Working state requires a future expiry'); }
        $acceptance=$this->policy->acceptsState($context,$before,$proposal);
        $state=new \AgenToolsPlatform\Core\Context\History\WorkingState($proposal,$expectedVersion+1,$acceptance,$expiresAt,$retention);
        $this->store->saveState($context,$state,$expectedVersion);return $state;
    }
}
