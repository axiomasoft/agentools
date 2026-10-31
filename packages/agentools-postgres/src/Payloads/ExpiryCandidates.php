<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};
use PDO;

/** Bounded metadata scan for the existing owner scheduler. It neither deletes nor resolves unknown effects. */
final readonly class ExpiryCandidates
{
    public function __construct(private RepositoryContext $environment)
    {
    }

    /** @return list<Ref> Resume with the last returned id; start a new pass to revisit held/unresolved entries. */
    public function scan(ExecutionContext $context, int $limit = 100, ?string $after = null): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Expiry scan limit must be 1..1000');
        }
        $this->environment->authorize($context);
        $db = $this->environment->db;
        $rows = $db->execute('SELECT id FROM ' . $db->table('payload_refs') . ' WHERE tenant_id=:tenant AND session_id=:session
            AND purpose=:purpose AND retention_until<=clock_timestamp() AND purged_at IS NULL AND id>:after
            ORDER BY id LIMIT ' . $limit, ['tenant' => $context->session->tenant->value, 'session' => $context->session->value,
                'purpose' => $context->purpose, 'after' => $after ?? '']);
        $refs = [];
        while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            $refs[] = new Ref($context->session->tenant, Connection::text($row, 'id'));
        }
        return $refs;
    }
}
