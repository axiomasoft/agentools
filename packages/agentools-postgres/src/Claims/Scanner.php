<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Claims;

use AgenToolsPlatform\Core\Common\RunId;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};
use PDO;

/** Read-only coordination candidates, including exhausted work for operator reconciliation. */
final readonly class Scanner
{
    public function __construct(private RepositoryContext $environment, private ClaimPolicy $policy = new ClaimPolicy())
    {
    }

    /** @return list<array{run: RunId, version: int, age_seconds: int, recovery_required: bool}> */
    public function scan(ExecutionContext $context, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Bounded scan required');
        }
        $this->environment->authorize($context);
        $db = $this->environment->db;
        $statement = $db->execute('SELECT r.id,r.version,
            floor(EXTRACT(epoch FROM clock_timestamp()-r.created_at))::bigint AS age_seconds,
            (r.fencing_epoch >= :maximum OR r.created_at + make_interval(secs => CAST(:deadline AS integer))
                <= clock_timestamp()) AS recovery_required
            FROM ' . $db->table('runs') . ' r JOIN ' . $db->table('turns') . ' t
            ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
            WHERE r.tenant_id=:tenant AND t.session_id=:session
            AND r.status NOT IN (\'completed\',\'failed\',\'cancelled\')
            AND (r.lease_until IS NULL OR r.lease_until <= clock_timestamp())
            ORDER BY r.created_at,r.id LIMIT ' . $limit, ['maximum' => $this->policy->maxClaims,
                'deadline' => $this->policy->deadlineSeconds, 'tenant' => $context->session->tenant->value,
                'session' => $context->session->value]);
        $result = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $result[] = ['run' => new RunId($context->session->tenant, Connection::text($row, 'id')),
                'version' => Connection::number($row, 'version'), 'age_seconds' => Connection::number($row, 'age_seconds'),
                'recovery_required' => $row['recovery_required'] === true];
        }
        return $result;
    }
}
