<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Runtime;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, RunId, RunStatus, TurnId};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Security\ExecutionContext;

final readonly class RunRepository
{
    public function __construct(private RepositoryContext $environment)
    {
    }

    public function get(ExecutionContext $context, RunId $run): ?StoredRun
    {
        $context->session->tenant->assertSame($run->tenant);
        $this->environment->authorize($context);
        return $this->load($context, $run);
    }

    /** Only the pre-claim intake advance belongs here; no provider/effect execution. */
    public function beginPreparation(ExecutionContext $context, ContextSnapshot $snapshot, RunId $run, int $expectedVersion): StoredRun
    {
        $context->session->tenant->assertSame($run->tenant);
        $this->environment->authorize($context, $snapshot);
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $context, $snapshot, $run, $expectedVersion): StoredRun {
            $saved = $this->load($context, $run, true);
            if ($saved === null) {
                throw new ContractViolation(ErrorCode::AccessDenied);
            }
            if ($saved->version !== $expectedVersion) {
                throw new ContractViolation(ErrorCode::OptimisticConflict);
            }
            if ($saved->status !== RunStatus::Accepted || $saved->fencingEpoch !== 0) {
                throw new ContractViolation(ErrorCode::PolicyConflict);
            }
            $checkpoint = $saved->checkpoint;
            $checkpoint['current_context'] = $context->toArray();
            $checkpoint['current_snapshot'] = $snapshot->toArray();
            $statement = $db->execute('UPDATE ' . $db->table('runs') . '
                SET status=:status,version=version+1,checkpoint=CAST(:checkpoint AS jsonb)
                WHERE tenant_id=:tenant AND id=:id AND version=:version AND fencing_epoch=0 AND claim_owner IS NULL', [
                'status' => RunStatus::Preparing->value, 'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
                'tenant' => $run->tenant->value, 'id' => $run->value, 'version' => $expectedVersion]);
            if ($statement->rowCount() !== 1) {
                throw new ContractViolation(ErrorCode::OptimisticConflict);
            }
            return $this->load($context, $run) ?? throw new StorageFailure();
        });
    }

    private function load(ExecutionContext $context, RunId $run, bool $lock = false): ?StoredRun
    {
        $db = $this->environment->db;
        $row = $db->row('SELECT r.id,r.turn_id,r.status,r.version,r.fencing_epoch,r.checkpoint
            FROM ' . $db->table('runs') . ' r JOIN ' . $db->table('turns') . ' t ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
            WHERE r.tenant_id=:tenant AND r.id=:id AND t.session_id=:session' . ($lock ? ' FOR UPDATE OF r' : ''),
            ['tenant' => $run->tenant->value, 'id' => $run->value, 'session' => $context->session->value]);
        if ($row === null) {
            return null;
        }
        $checkpoint = json_decode(Connection::text($row, 'checkpoint'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($checkpoint) || array_is_list($checkpoint)) {
            throw new StorageFailure();
        }
        $named = [];
        foreach ($checkpoint as $key => $value) {
            if (!is_string($key)) {
                throw new StorageFailure();
            }
            $named[$key] = $value;
        }
        return new StoredRun($run, new TurnId($run->tenant, Connection::text($row, 'turn_id')),
            RunStatus::from(Connection::text($row, 'status')), Connection::number($row, 'version'),
            Connection::number($row, 'fencing_epoch'), $named);
    }
}
