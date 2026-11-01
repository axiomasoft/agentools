<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Operations;

use AgenToolsPlatform\Core\Actions\Cancellation\Operation;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, RunId, RunStatus};
use AgenToolsPlatform\Postgres\Runtime\Connection;

final readonly class RunCancellation
{
    public function __construct(private Audit $audit) {}

    /** @return array<string,mixed> */
    public function apply(Operation $command): array
    {
        if (!$command->target instanceof RunId || !in_array($command->kind,['run_cancel','run_finalize'],true)) { throw new \InvalidArgumentException('Run command required'); }
        return $this->audit->local($command,function () use ($command): array {
            $db=$this->audit->environment->db;
            $keys=['tenant'=>$command->id->tenant->value,'id'=>$command->target->value];
            $row=$db->row('SELECT r.* FROM '.$db->table('runs').' r JOIN '.$db->table('turns').' t ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
                WHERE r.tenant_id=:tenant AND r.id=:id AND t.session_id=:session FOR UPDATE OF r',
                $keys+['session'=>$command->context->session->value]) ?? throw new ContractViolation(ErrorCode::AccessDenied);
            if (Connection::number($row,'version')!==$command->expectedVersion) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            if (Connection::number($row,'fencing_epoch')!==$command->expectedEpoch) { throw new ContractViolation(ErrorCode::StaleClaim); }
            $unresolved=$db->row('SELECT i.id FROM '.$db->table('effect_intents').' i WHERE i.tenant_id=:tenant AND i.outcome IN (\'pending\',\'unknown\')
                AND (EXISTS (SELECT 1 FROM '.$db->table('run_steps').' s WHERE s.tenant_id=i.tenant_id AND s.run_id=:run AND s.action_ref=i.action_ref)
                OR EXISTS (SELECT 1 FROM '.$db->table('proposal_map').' p WHERE p.tenant_id=i.tenant_id AND p.run_id=:prun AND p.action_ref=i.action_ref)) LIMIT 1',
                ['tenant'=>$keys['tenant'],'run'=>$keys['id'],'prun'=>$keys['id']])!==null;
            $unknownInvocation=$db->row('SELECT i.id FROM '.$db->table('invocation_intents').' i LEFT JOIN '.$db->table('invocation_results').' r
                ON r.tenant_id=i.tenant_id AND r.invocation_id=i.id WHERE i.tenant_id=:tenant AND i.run_id=:run AND (r.outcome IS NULL OR r.outcome=\'unknown\') LIMIT 1',
                ['tenant'=>$keys['tenant'],'run'=>$keys['id']])!==null;
            $activeAction=false;
            if ($command->kind==='run_finalize') {
                // Fence late preparation of an accepted Action while deciding terminality.
                $linked=$db->execute('SELECT a.id,a.state FROM '.$db->table('action_current').' a WHERE a.tenant_id=:tenant
                    AND (EXISTS (SELECT 1 FROM '.$db->table('run_steps').' s WHERE s.tenant_id=a.tenant_id AND s.run_id=:run AND s.action_ref=a.id)
                    OR EXISTS (SELECT 1 FROM '.$db->table('proposal_map').' p WHERE p.tenant_id=a.tenant_id AND p.run_id=:prun AND p.action_ref=a.id))
                    ORDER BY a.id FOR UPDATE OF a',['tenant'=>$keys['tenant'],'run'=>$keys['id'],'prun'=>$keys['id']])->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($linked as $action) {
                    if (!is_array($action) || !isset($action['state']) || !is_string($action['state'])) { throw new \UnexpectedValueException('Invalid linked Action projection'); }
                    $projection=json_decode($action['state'],true,512,JSON_THROW_ON_ERROR);
                    if (!is_array($projection) || !in_array($projection['status']??null,['completed','failed','rejected','expired','cancelled'],true)) { $activeAction=true; }
                }
            }
            $status=RunStatus::from(Connection::text($row,'status'));
            $next=(new \AgenToolsPlatform\Core\Runtime\RunCancellation())->next($status,$command,$unresolved||$unknownInvocation||$activeAction);
            $version=Connection::number($row,'version'); $epoch=Connection::number($row,'fencing_epoch');
            if ($next!==$status) {
                $db->execute('UPDATE '.$db->table('runs').' SET version=version+1,status=:status,fencing_epoch=fencing_epoch+1,
                    claim_owner=NULL,lease_until=NULL,terminal_code=:terminal WHERE tenant_id=:tenant AND id=:id',
                    $keys+['status'=>$next->value,'terminal'=>$next===RunStatus::Cancelled?'operator_cancelled':null]);
                ++$version; ++$epoch;
            }
            return ['run_id'=>$keys['id'],'status'=>$next->value,'version'=>$version,'epoch'=>$epoch,
                'unresolved_effects'=>$unresolved,'unknown_invocations'=>$unknownInvocation];
        });
    }
}
