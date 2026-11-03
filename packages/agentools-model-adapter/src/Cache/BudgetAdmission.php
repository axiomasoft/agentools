<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Cache;

use AgenToolsPlatform\Core\Budget\{AdmissionScope,BudgetBoundary};
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Models\Cache\{Admission,Prepared};

/** Host supplies actual invocation scope; cache lookup never substitutes owner admission. */
final readonly class BudgetAdmission implements Admission
{
    /** @param \Closure(Prepared): AdmissionScope $scope */
    public function __construct(private BudgetBoundary $budget,private \Closure $scope) {}
    public function admit(Prepared $prepared,UtcInstant $now): void
    {
        $scope=($this->scope)($prepared); $key=$prepared->key;
        $key->id->tenant->assertSame($scope->operationKey->tenant);
        if (($scope->content['deployment_ref']??null)!==$key->dimensions['deployment_ref']
            || ($scope->content['context_hash']??null)!==$prepared->contextHash->value) { throw new \LogicException('Cache admission binding changed'); }
        if ($this->budget->admit($scope,$now)->status!=='admitted') { throw new \LogicException('Cache-independent budget refusal'); }
    }
}
