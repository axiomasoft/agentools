<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Confirmation;

use AgenToolsPlatform\Core\Actions\CancelRequest;
use AgenToolsPlatform\Core\Common\{ActionId, ContractViolation, ErrorCode, IntegrityConflict};
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** Request history is independent of known owner outcomes and never triggers compensation. */
final readonly class CancellationRepository
{
    public function __construct(private RepositoryContext $environment) {}

    public function request(ActionId $id,CancelRequest $request): void
    {
        $context=$request->context;
        $id->tenant->assertSame($context->identity->tenant);
        $this->environment->authorize($context);
        $db=$this->environment->db;
        $scope=$context->scopeHash($this->environment->hasher)->value;
        $allowed=$db->row('SELECT id FROM '.$db->table('action_submissions').' WHERE tenant_id=:tenant AND id=:id AND actor_ref=:actor AND scope_hash=:scope',
            ['tenant'=>$id->tenant->value,'id'=>$id->value,'actor'=>$context->identity->actorRef->value,'scope'=>$scope]);
        if ($allowed===null) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $hash=$this->environment->hasher->hash($id->tenant,'cancellation-command',[$id->value,$request->commandId->value,$request->expectedVersion,$scope])->value;
        $db->transaction(function () use ($db,$id,$request,$hash): void {
            $journal=new Journal($this->environment);
            $state=$journal->read($id,true);
            $saved=$db->row('SELECT request_hash FROM '.$db->table('action_cancellation_requests').' WHERE tenant_id=:tenant AND id=:id',
                ['tenant'=>$id->tenant->value,'id'=>$request->commandId->value]);
            if ($saved!==null) {
                if (Connection::text($saved,'request_hash')!==$hash) { throw new IntegrityConflict('Cancellation command conflict'); }
                return;
            }
            if ($state->seq!==$request->expectedVersion) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            $db->execute('INSERT INTO '.$db->table('action_cancellation_requests').' (tenant_id,id,action_ref,request_hash) VALUES (:tenant,:id,:action,:hash)',
                ['tenant'=>$id->tenant->value,'id'=>$request->commandId->value,'action'=>$id->value,'hash'=>$hash]);
            if (!$state->status?->isTerminal() && !$state->cancellationRequested) {
                $journal->append($id,$state,[['kind'=>'cancellation_requested','payload'=>['command_ref'=>$request->commandId->value]]]);
            }
        });
    }
}
