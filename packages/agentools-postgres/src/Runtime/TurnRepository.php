<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Runtime;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref, TurnId};
use AgenToolsPlatform\Core\Security\ExecutionContext;

final readonly class TurnRepository
{
    public function __construct(private RepositoryContext $environment)
    {
    }

    public function get(ExecutionContext $context, TurnId $turn): ?StoredTurn
    {
        $context->session->tenant->assertSame($turn->tenant);
        $this->environment->authorize($context);
        $db = $this->environment->db;
        $row = $db->row('SELECT id,source,source_event_key,payload_hash,input_ref FROM ' . $db->table('turns') . '
            WHERE tenant_id=:tenant AND session_id=:session AND id=:id', [
                'tenant' => $turn->tenant->value, 'session' => $context->session->value, 'id' => $turn->value]);
        return $row === null ? null : new StoredTurn($turn, $context->session,
            Connection::text($row, 'source'), Connection::text($row, 'source_event_key'),
            new PayloadHash('sha-256:' . Connection::text($row, 'payload_hash')),
            new Ref($turn->tenant, Connection::text($row, 'input_ref')));
    }
}
