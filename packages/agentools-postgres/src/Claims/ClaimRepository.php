<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Claims;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, RunId, RunStatus, TurnId};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext, StorageFailure, StoredRun};

final readonly class ClaimRepository
{
    public function __construct(private RepositoryContext $environment, private ClaimPolicy $policy = new ClaimPolicy())
    {
    }

    /** Queue payloads cannot create work. Null means missing, busy, terminal or already advanced. */
    public function claim(ExecutionContext $context, ContextSnapshot $snapshot, RunId $run, int $expectedVersion): ?RunClaim
    {
        $context->session->tenant->assertSame($run->tenant);
        $this->environment->authorize($context, $snapshot);
        $db = $this->environment->db;
        $owner = bin2hex(random_bytes(32));
        return $db->transaction(function () use ($db, $context, $run, $expectedVersion, $owner): ?RunClaim {
            $row = $db->row('SELECT r.*, (r.fencing_epoch >= :maximum OR
                r.created_at + make_interval(secs => CAST(:deadline AS integer)) <= clock_timestamp()) AS exhausted
                FROM ' . $db->table('runs') . ' r JOIN ' . $db->table('turns') . ' t
                ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
                WHERE r.tenant_id=:tenant AND r.id=:id AND t.session_id=:session AND r.version=:version
                AND r.status NOT IN (\'completed\',\'failed\',\'cancelled\')
                AND (r.lease_until IS NULL OR r.lease_until <= clock_timestamp()) FOR UPDATE OF r SKIP LOCKED', [
                    'maximum' => $this->policy->maxClaims, 'deadline' => $this->policy->deadlineSeconds,
                    'tenant' => $run->tenant->value, 'id' => $run->value,
                    'session' => $context->session->value, 'version' => $expectedVersion]);
            if ($row === null) {
                return null;
            }
            if ($row['exhausted'] === true) {
                throw new ContractViolation(ErrorCode::ManualResolutionRequired);
            }
            $updated = $db->row('UPDATE ' . $db->table('runs') . '
                SET version=version+1,fencing_epoch=fencing_epoch+1,claim_owner=:owner,
                lease_until=clock_timestamp() + CAST(:lease AS integer) * interval \'1 millisecond\'
                WHERE tenant_id=:tenant AND id=:id AND version=:version RETURNING *', [
                    'owner' => $owner, 'lease' => $this->policy->leaseMilliseconds,
                    'tenant' => $run->tenant->value, 'id' => $run->value, 'version' => $expectedVersion]);
            return $this->hydrate($run, $updated ?? throw new StorageFailure(), $owner);
        });
    }

    public function heartbeat(ExecutionContext $context, ContextSnapshot $snapshot, RunClaim $claim): RunClaim
    {
        $parameters = $this->authorize($context, $snapshot, $claim);
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $claim, $parameters): RunClaim {
            $row = $db->row('UPDATE ' . $db->table('runs') . ' r SET version=version+1,
                lease_until=clock_timestamp() + CAST(:lease AS integer) * interval \'1 millisecond\'
                WHERE ' . $this->fence() . ' RETURNING r.*', $parameters + ['lease' => $this->policy->leaseMilliseconds]);
            return $this->hydrate($claim->state->id, $row ?? throw new ContractViolation(ErrorCode::StaleClaim), $claim->owner);
        });
    }

    /**
     * Accepted intake evidence stays immutable. Only bounded progress/context and legal status advance.
     * @param array<mixed> $progress Untrusted checkpoint fragment, validated as a named object.
     */
    public function checkpoint(ExecutionContext $context, ContextSnapshot $snapshot, RunClaim $claim,
        RunStatus $next, array $progress, ?string $terminalCode = null): RunClaim
    {
        $parameters = $this->authorize($context, $snapshot, $claim);
        if ($next->terminal() !== ($terminalCode !== null)
            || ($terminalCode !== null && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $terminalCode) !== 1)) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        foreach (array_keys($progress) as $key) {
            if (!is_string($key)) {
                throw new ContractViolation(ErrorCode::InvalidInput);
            }
        }
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $claim, $parameters, $context, $snapshot, $next, $progress, $terminalCode): RunClaim {
            $saved = $db->row('SELECT r.* FROM ' . $db->table('runs') . ' r WHERE ' . $this->fence() . ' FOR UPDATE OF r', $parameters)
                ?? throw new ContractViolation(ErrorCode::StaleClaim);
            if (!RunStatus::from(Connection::text($saved, 'status'))->allows($next)) {
                throw new ContractViolation(ErrorCode::InvalidInput);
            }
            $checkpoint = $this->hydrate($claim->state->id, $saved, $claim->owner)->state->checkpoint;
            $checkpoint['progress'] = (object) $progress;
            $checkpoint['current_context'] = $context->toArray();
            $checkpoint['current_snapshot'] = $snapshot->toArray();
            $encoded = json_encode($checkpoint, JSON_THROW_ON_ERROR);
            if (strlen($encoded) > 16384) {
                throw new ContractViolation(ErrorCode::InvalidInput);
            }
            $row = $db->row('UPDATE ' . $db->table('runs') . ' r SET version=version+1,status=:status,
                checkpoint=CAST(:checkpoint AS jsonb),terminal_code=:terminal,
                claim_owner=CASE WHEN :release_owner=1 THEN NULL ELSE claim_owner END,
                lease_until=CASE WHEN :release_lease=1 THEN NULL ELSE lease_until END
                WHERE ' . $this->fence() . ' RETURNING r.*', $parameters + [
                    'status' => $next->value, 'checkpoint' => $encoded, 'terminal' => $terminalCode,
                    'release_owner' => $next->terminal() ? 1 : 0, 'release_lease' => $next->terminal() ? 1 : 0]);
            return $this->hydrate($claim->state->id, $row ?? throw new ContractViolation(ErrorCode::StaleClaim), $claim->owner);
        });
    }

    /** @return array<string, scalar|null> */
    private function authorize(ExecutionContext $context, ContextSnapshot $snapshot, RunClaim $claim): array
    {
        $run = $claim->state->id;
        $context->session->tenant->assertSame($run->tenant);
        $this->environment->authorize($context, $snapshot);
        return ['tenant' => $run->tenant->value, 'id' => $run->value, 'version' => $claim->state->version,
            'epoch' => $claim->state->fencingEpoch, 'owner' => $claim->owner, 'session' => $context->session->value,
            'deadline' => $this->policy->deadlineSeconds];
    }

    private function fence(): string
    {
        return 'r.tenant_id=:tenant AND r.id=:id AND r.version=:version AND r.fencing_epoch=:epoch
            AND r.claim_owner=:owner AND r.lease_until > clock_timestamp()
            AND r.created_at + make_interval(secs => CAST(:deadline AS integer)) > clock_timestamp()
            AND r.status NOT IN (\'completed\',\'failed\',\'cancelled\')
            AND EXISTS (SELECT 1 FROM ' . $this->environment->db->table('turns') . ' t
                WHERE t.tenant_id=r.tenant_id AND t.id=r.turn_id AND t.session_id=:session)';
    }

    /** @param array<string, mixed> $row */
    private function hydrate(RunId $run, array $row, string $owner): RunClaim
    {
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
        return new RunClaim(new StoredRun($run, new TurnId($run->tenant, Connection::text($row, 'turn_id')),
            RunStatus::from(Connection::text($row, 'status')), Connection::number($row, 'version'),
            Connection::number($row, 'fencing_epoch'), $named), $owner,
            $row['lease_until'] === null ? null : Connection::text($row, 'lease_until'));
    }
}
