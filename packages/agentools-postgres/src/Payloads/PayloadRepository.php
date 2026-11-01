<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref, UtcInstant};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext, StorageFailure};
use PDO;
use Throwable;

/** Tenant/session-bound technical refs. External IO occurs only between committed SQL phases. */
final readonly class PayloadRepository
{
    public function __construct(private RepositoryContext $environment, private PayloadPolicy $policy,
        private EncryptedPayloadStore $store)
    {
    }

    public function register(ExecutionContext $context, PayloadDescriptor $payload, PayloadHandle $handle): void
    {
        $payload->ref->assertSame($handle->slot);
        $decision = $this->grant($context, $payload, 'register');
        $binding = $this->bindingHash($context, $payload, $handle);
        $db = $this->environment->db;
        $db->transaction(function () use ($db, $context, $payload, $handle, $decision, $binding): void {
            $db->execute('INSERT INTO ' . $db->table('payload_refs') . '
                (tenant_id,id,session_id,binding_hash,content_hash,encrypted_ref,encryption_key_ref,classification,purpose,retention_until)
                VALUES (:tenant,:id,:session,:binding,:hash,:encrypted,:key,:classification,:purpose,CAST(:expiry AS timestamptz))
                ON CONFLICT (tenant_id,id) DO NOTHING', ['tenant' => $payload->ref->tenant->value, 'id' => $payload->ref->value,
                    'session' => $context->session->value, 'binding' => $binding, 'hash' => substr($payload->contentHash->value, 8),
                    'encrypted' => $handle->encryptedRef->value, 'key' => $handle->encryptionKeyRef->value,
                    'classification' => $payload->classification, 'purpose' => $payload->purpose, 'expiry' => $payload->retentionUntil->value]);
            $row = $db->row('SELECT binding_hash FROM ' . $db->table('payload_refs') . ' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',
                ['tenant' => $payload->ref->tenant->value, 'id' => $payload->ref->value]);
            if ($row === null || Connection::text($row, 'binding_hash') !== $binding) {
                throw new IntegrityConflict('Payload identity binding conflict');
            }
            $this->audit($context, $payload->ref, 'register', 'authorized', $decision);
        });
    }

    /** Safe metadata and tombstone status, never handles/bytes. */
    public function status(ExecutionContext $context, Ref $ref): string
    {
        $this->authorize($context, $ref);
        $row = $this->load($context, $ref);
        $decision = $this->grant($context, $this->descriptor($ref, $row), 'status');
        $db = $this->environment->db;
        $db->transaction(fn () => $this->audit($context, $ref, 'status', 'authorized', $decision));
        return $row['purged_at'] !== null ? 'purged' : ($row['purge_pending_at'] !== null ? 'purging' : ($row['readable'] === true ? 'available' : 'expired'));
    }

    public function materialize(ExecutionContext $context, Ref $ref, PayloadHandle $copy): void
    {
        $this->authorize($context, $ref);
        $ref->tenant->assertSame($copy->slot->tenant);
        if ($ref->value === $copy->slot->value) {
            throw new IntegrityConflict('Materialization requires a separate slot');
        }
        $payload = $this->descriptor($ref, $this->load($context, $ref));
        $decision = $this->grant($context, $payload, 'materialize');
        $binding = $this->bindingHash($context, $payload, $copy);
        $db = $this->environment->db;
        $db->transaction(function () use ($db, $context, $ref, $copy, $decision, $binding): void {
            $this->requireReadable($this->load($context, $ref, true));
            $db->execute('INSERT INTO ' . $db->table('payload_materializations') . '
                (tenant_id,id,payload_id,binding_hash,encrypted_ref,encryption_key_ref)
                VALUES (:tenant,:id,:payload,:hash,:encrypted,:key) ON CONFLICT (tenant_id,id) DO NOTHING', [
                    'tenant' => $ref->tenant->value, 'id' => $copy->slot->value, 'payload' => $ref->value, 'hash' => $binding,
                    'encrypted' => $copy->encryptedRef->value, 'key' => $copy->encryptionKeyRef->value]);
            $saved = $db->row('SELECT binding_hash,payload_id FROM ' . $db->table('payload_materializations') . ' WHERE tenant_id=:tenant AND id=:id',
                ['tenant' => $ref->tenant->value, 'id' => $copy->slot->value]);
            if ($saved === null || Connection::text($saved, 'binding_hash') !== $binding || Connection::text($saved, 'payload_id') !== $ref->value) {
                throw new IntegrityConflict('Materialized payload identity conflict');
            }
            $this->audit($context, $ref, 'materialize', 'authorized', $decision);
        });
    }

    public function dereference(ExecutionContext $context, Ref $ref): string
    {
        $this->authorize($context, $ref);
        $row = $this->load($context, $ref);
        $payload = $this->descriptor($ref, $row);
        $db = $this->environment->db;
        try {
            $decision = $this->grant($context, $payload, 'dereference');
        } catch (Throwable) {
            $db->transaction(fn () => $this->audit($context, $ref, 'dereference', 'denied', null));
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        $prepared = $db->transaction(function () use ($context, $ref, $decision): ?array {
            $saved = $this->load($context, $ref, true);
            $allowed = $saved['readable'] === true;
            $this->audit($context, $ref, 'dereference', $allowed ? 'authorized' : 'denied', $decision);
            return $allowed ? $saved : null;
        });
        if ($prepared === null) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        $db->assertIdle();
        $readFailed = false;
        try {
            try {
                $bytes = $this->store->read($context, $this->handle($ref, $prepared));
            } catch (Throwable) {
                $readFailed = true;
                $db->transaction(fn () => $this->audit($context, $ref, 'dereference', 'unknown', $decision));
                throw new StorageFailure();
            }
        } finally {
            try {
                $this->resetStore();
            } catch (StorageFailure $error) {
                if (!$readFailed) {
                    $db->transaction(fn () => $this->audit($context, $ref, 'dereference', 'unknown', $decision));
                }
                throw $error;
            }
        }
        if (!hash_equals($payload->contentHash->value, 'sha-256:' . hash('sha256', $bytes))) {
            $db->transaction(fn () => $this->audit($context, $ref, 'dereference', 'integrity_conflict', $decision));
            throw new IntegrityConflict('Payload content hash conflict');
        }
        try {
            $release = $this->grant($context, $payload, 'dereference');
        } catch (Throwable) {
            $db->transaction(fn () => $this->audit($context, $ref, 'dereference', 'denied', null));
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        $allowed = $db->transaction(function () use ($context, $ref, $prepared, $release): bool {
            $latest = $this->load($context, $ref, true);
            $allowed = $latest['readable'] === true && Connection::number($latest, 'version') === Connection::number($prepared, 'version');
            $this->audit($context, $ref, 'dereference', $allowed ? 'released' : 'denied', $release);
            return $allowed;
        });
        if (!$allowed) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        return $bytes;
    }

    public function purge(ExecutionContext $context, Ref $ref): void
    {
        $this->authorize($context, $ref);
        $payload = $this->descriptor($ref, $this->load($context, $ref));
        $decision = $this->grant($context, $payload, 'purge');
        try {
            $supported = $this->store->supportsIdempotentPurge();
        } catch (Throwable) {
            throw new StorageFailure();
        }
        if (!$supported) {
            throw new ContractViolation(ErrorCode::ManualResolutionRequired);
        }
        $db = $this->environment->db;
        $prepared = $db->transaction(function () use ($db, $context, $ref, $decision): ?array {
            $row = $this->load($context, $ref, true);
            if ($row['purged_at'] !== null) {
                return null;
            }
            $state = $db->row('SELECT ' . $db->table('platform_payload_unresolved') . '(:tenant,:id) AS unresolved',
                ['tenant' => $ref->tenant->value, 'id' => $ref->value]);
            if ($state === null || $state['unresolved'] !== false) {
                throw new ContractViolation(ErrorCode::ManualResolutionRequired);
            }
            if ($row['purge_pending_at'] === null) {
                $db->execute('UPDATE ' . $db->table('payload_refs') . ' SET version=version+1,purge_pending_at=clock_timestamp(),purge_token=:token
                    WHERE tenant_id=:tenant AND id=:id AND version=:version', ['token' => bin2hex(random_bytes(32)),
                        'tenant' => $ref->tenant->value, 'id' => $ref->value, 'version' => Connection::number($row, 'version')]);
                $row = $this->load($context, $ref, true);
            }
            $this->audit($context, $ref, 'purge', 'authorized', $decision);
            return $row;
        });
        if ($prepared === null) {
            return;
        }
        $handles = [$this->handle($ref, $prepared)];
        $statement = $db->execute('SELECT * FROM ' . $db->table('payload_materializations') . ' WHERE tenant_id=:tenant AND payload_id=:id AND purged_at IS NULL ORDER BY id',
            ['tenant' => $ref->tenant->value, 'id' => $ref->value]);
        while (($copy = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $handles[] = $this->handle(new Ref($ref->tenant, Connection::text($copy, 'id')), $copy);
        }
        $purgeFailed = false;
        try {
            try {
                foreach ($handles as $handle) {
                    $db->assertIdle();
                    $operation = hash('sha256', Connection::text($prepared, 'purge_token') . ':' . $handle->slot->value);
                    $this->store->purge($context, $handle, $operation);
                }
            } catch (Throwable) {
                $purgeFailed = true;
                $db->transaction(fn () => $this->audit($context, $ref, 'purge', 'unknown', $decision));
                throw new StorageFailure();
            }
        } finally {
            try {
                $this->resetStore();
            } catch (StorageFailure $error) {
                if (!$purgeFailed) {
                    $db->transaction(fn () => $this->audit($context, $ref, 'purge', 'unknown', $decision));
                }
                throw $error;
            }
        }
        $release = $this->grant($context, $payload, 'purge');
        $db->transaction(function () use ($db, $context, $ref, $prepared, $release): void {
            $row = $this->load($context, $ref, true);
            if ($row['purged_at'] !== null) {
                return;
            }
            if (Connection::text($row, 'purge_token') !== Connection::text($prepared, 'purge_token')) {
                throw new ContractViolation(ErrorCode::OptimisticConflict);
            }
            $db->execute('UPDATE ' . $db->table('payload_refs') . ' SET version=version+1,encrypted_ref=NULL,encryption_key_ref=NULL,purged_at=clock_timestamp()
                WHERE tenant_id=:tenant AND id=:id', ['tenant' => $ref->tenant->value, 'id' => $ref->value]);
            $db->execute('UPDATE ' . $db->table('payload_materializations') . ' SET version=version+1,encrypted_ref=NULL,encryption_key_ref=NULL,purged_at=clock_timestamp()
                WHERE tenant_id=:tenant AND payload_id=:id AND purged_at IS NULL', ['tenant' => $ref->tenant->value, 'id' => $ref->value]);
            $this->audit($context, $ref, 'purge', 'purged', $release);
        });
    }

    private function authorize(ExecutionContext $context, Ref $ref): void
    {
        $ref->tenant->assertSame($context->session->tenant);
        $this->environment->db->assertIdle();
        try {
            $this->environment->authorize($context);
        } catch (Throwable) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }

    private function grant(ExecutionContext $context, PayloadDescriptor $payload, string $operation): Ref
    {
        $this->authorize($context, $payload->ref);
        if ($context->purpose !== $payload->purpose) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        try {
            $decision = $this->policy->authorize($context, $payload, $operation);
            $payload->ref->tenant->assertSame($decision->tenant);
            return $decision;
        } catch (Throwable) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }

    /** @return array<string, mixed> */
    private function load(ExecutionContext $context, Ref $ref, bool $lock = false): array
    {
        $db = $this->environment->db;
        return $db->row('SELECT *, (retention_until>clock_timestamp() AND purge_pending_at IS NULL AND purged_at IS NULL) AS readable
            FROM ' . $db->table('payload_refs') . ' WHERE tenant_id=:tenant AND id=:id AND session_id=:session' . ($lock ? ' FOR UPDATE' : ''),
            ['tenant' => $ref->tenant->value, 'id' => $ref->value, 'session' => $context->session->value])
            ?? throw new ContractViolation(ErrorCode::AccessDenied);
    }

    /** @param array<string, mixed> $row */
    private function descriptor(Ref $ref, array $row): PayloadDescriptor
    {
        $date = new \DateTimeImmutable(Connection::text($row, 'retention_until'));
        return new PayloadDescriptor($ref, new PayloadHash('sha-256:' . Connection::text($row, 'content_hash')),
            Connection::text($row, 'classification'), Connection::text($row, 'purpose'),
            new UtcInstant($date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')));
    }

    /** @param array<string, mixed> $row */
    private function handle(Ref $ref, array $row): PayloadHandle
    {
        return new PayloadHandle($ref, new Ref($ref->tenant, Connection::text($row, 'encrypted_ref')),
            new Ref($ref->tenant, Connection::text($row, 'encryption_key_ref')));
    }

    /** @param array<string, mixed> $row */
    private function requireReadable(array $row): void
    {
        if ($row['readable'] !== true) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }

    private function bindingHash(ExecutionContext $context, PayloadDescriptor $payload, PayloadHandle $handle): string
    {
        return substr($this->environment->hasher->hash($payload->ref->tenant, 'payload-binding', [
            $context->session->value, $payload->ref->value, $payload->contentHash->value, $payload->classification,
            $payload->purpose, $payload->retentionUntil->value, $handle->slot->value,
            $handle->encryptedRef->value, $handle->encryptionKeyRef->value])->value, 8);
    }

    private function audit(ExecutionContext $context, Ref $ref, string $operation, string $outcome, ?Ref $decision): void
    {
        $db = $this->environment->db;
        $db->execute('INSERT INTO ' . $db->table('payload_access_audit') . '(tenant_id,id,payload_id,actor_ref,purpose,operation,outcome,decision_ref)
            VALUES (:tenant,:id,:payload,:actor,:purpose,:operation,:outcome,:decision)', ['tenant' => $ref->tenant->value,
                'id' => bin2hex(random_bytes(32)), 'payload' => $ref->value, 'actor' => $context->identity->actorRef->value,
                'purpose' => $context->purpose, 'operation' => $operation, 'outcome' => $outcome, 'decision' => $decision?->value]);
    }

    private function resetStore(): void
    {
        try {
            $this->store->reset();
        } catch (Throwable) {
            throw new StorageFailure();
        }
    }
}
