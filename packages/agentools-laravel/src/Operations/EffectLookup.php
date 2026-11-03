<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Operations;

use AgenToolsPlatform\Core\Actions\Effects\Request;
use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,IntegrityConflict,Ref};
use AgenToolsPlatform\Postgres\Effects\Service;

/** Host can select this bound lookup by original effect identity; it never enables effect retry. */
final readonly class EffectLookup
{
    public function __construct(private Request $request,private Service $service) {}
    /** @param \Closure():void $reauthorize */
    public function lookup(Command $command,Observation $observation,\Closure $reauthorize): ?Ref
    {
        if (!$command->apply || !in_array($command->verb,['recover','reconcile'],true)) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        $command->id->tenant->assertSame($this->request->action->tenant);
        if ($observation->kind!=='effect' || $observation->key!=='intent:'.$this->request->effectKey
            || $observation->requestHash!==substr($this->request->requestHash->value,8)
            || $observation->ownerKey!==$this->request->operationKey) {
            throw new IntegrityConflict('Effect lookup identity conflict');
        }
        $reauthorize();
        $this->service->assertTarget($this->request->action,$observation->epoch);
        try { return $this->service->reconcile(false)->receipt; }
        catch (\AgenTools\Effects\EffectReconciliationRequired) { return null; }
    }
}
