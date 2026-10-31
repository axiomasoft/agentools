<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};
use Closure;

/** Durable holds, independent of payload readability. Host must authorize each hold/release. */
final readonly class PayloadHolds
{
    /** @param Closure(ExecutionContext,Ref,Ref,string):Ref $authority Fresh exact payload/hold decision, never a client assertion. */
    public function __construct(private RepositoryContext $environment, private Closure $authority)
    {
    }

    public function place(ExecutionContext $context, Ref $payload, Ref $hold): void
    {
        $decision = $this->authorize($context, $payload, $hold, 'hold');
        $db = $this->environment->db;
        $db->transaction(function () use ($db, $context, $payload, $hold, $decision): void {
            $this->parent($context, $payload);
            $saved = $db->row('SELECT payload_id,authority_ref FROM ' . $db->table('payload_holds') . ' WHERE tenant_id=:tenant AND id=:id',
                ['tenant' => $hold->tenant->value, 'id' => $hold->value]);
            if ($saved !== null) {
                if (Connection::text($saved, 'payload_id') !== $payload->value
                    || Connection::text($saved, 'authority_ref') !== $decision->value) {
                    throw new IntegrityConflict('Hold identity binding conflict');
                }
                return; // Replay of a released hold never reactivates it.
            }
            $db->execute('INSERT INTO ' . $db->table('payload_holds') . '(tenant_id,id,payload_id,authority_ref)
                VALUES (:tenant,:id,:payload,:authority)', ['tenant' => $hold->tenant->value, 'id' => $hold->value,
                    'payload' => $payload->value, 'authority' => $decision->value]);
        });
    }

    public function release(ExecutionContext $context, Ref $payload, Ref $hold): void
    {
        $decision = $this->authorize($context, $payload, $hold, 'release-hold');
        $db = $this->environment->db;
        $db->transaction(function () use ($db, $context, $payload, $hold, $decision): void {
            $this->parent($context, $payload);
            $saved = $db->row('SELECT payload_id,released_by FROM ' . $db->table('payload_holds') . ' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',
                ['tenant' => $hold->tenant->value, 'id' => $hold->value]);
            if ($saved === null || Connection::text($saved, 'payload_id') !== $payload->value) {
                throw new ContractViolation(ErrorCode::AccessDenied);
            }
            if ($saved['released_by'] !== null) {
                if ($saved['released_by'] !== $decision->value) {
                    throw new IntegrityConflict('Hold release binding conflict');
                }
                return;
            }
            $db->execute('UPDATE ' . $db->table('payload_holds') . ' SET version=version+1,released_by=:decision,released_at=clock_timestamp()
                WHERE tenant_id=:tenant AND id=:id', ['decision' => $decision->value, 'tenant' => $hold->tenant->value, 'id' => $hold->value]);
        });
    }

    private function authorize(ExecutionContext $context, Ref $payload, Ref $hold, string $operation): Ref
    {
        $context->session->tenant->assertSame($payload->tenant);
        $payload->tenant->assertSame($hold->tenant);
        $this->environment->authorize($context);
        $decision = ($this->authority)($context, $payload, $hold, $operation);
        $payload->tenant->assertSame($decision->tenant);
        return $decision;
    }

    /** Lock the same row as purge, without requiring a readable/unexpired payload. */
    private function parent(ExecutionContext $context, Ref $payload): void
    {
        if ($this->environment->db->row('SELECT id FROM ' . $this->environment->db->table('payload_refs') . '
            WHERE tenant_id=:tenant AND id=:id AND session_id=:session FOR UPDATE', ['tenant' => $payload->tenant->value,
                'id' => $payload->value, 'session' => $context->session->value]) === null) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }
}
