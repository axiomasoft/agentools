<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};
use Closure;
use PDO;

/** Actual encrypted derived stores. Receipts cover only their registered payloads. */
final readonly class DerivedPayloadRepository
{
    public const array DEPENDENCIES = ['context', 'summary', 'memory-candidates', 'history-search',
        'provider-cache', 'context-cache', 'temporary-payloads'];

    /** @param Closure(ExecutionContext,Ref,string):void $authorizeSource Fresh exact-source read/cleanup authority. */
    public function __construct(private RepositoryContext $environment, private PayloadRepository $payloads,
        private Closure $authorizeSource) {}

    /** Register before producing bytes. Existing payload registration owns TTL, keys and copies. */
    public function bind(ExecutionContext $context, Ref $source, int $revision, string $dependency, Ref $payload): void
    {
        $this->authorize($context, $source, $dependency, 'bind');
        $source->tenant->assertSame($payload->tenant);
        if ($revision < 1) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        $db = $this->environment->db;
        $id = substr($this->environment->hasher->hash($source->tenant, 'derived-payload-v1',
            [$context->session->value, $source->value, $dependency, $payload->value])->value, 8);
        $db->transaction(function () use ($db, $context, $source, $revision, $dependency, $payload, $id): void {
            $saved = $db->row('SELECT source_revision FROM ' . $db->table('derived_payloads') . ' WHERE tenant_id=:tenant AND id=:id',
                ['tenant' => $source->tenant->value, 'id' => $id]);
            if ($saved !== null) {
                if (Connection::number($saved, 'source_revision') !== $revision) {
                    throw new IntegrityConflict('Derived payload revision conflict');
                }
                $this->current($source, $revision);
                return;
            }
            $db->execute('INSERT INTO ' . $db->table('derived_payloads') . '
                (tenant_id,id,session_id,source_ref,source_revision,dependency,payload_id)
                VALUES (:tenant,:id,:session,:source,:revision,:dependency,:payload) ON CONFLICT DO NOTHING',
                ['tenant' => $source->tenant->value, 'id' => $id, 'session' => $context->session->value,
                    'source' => $source->value, 'revision' => $revision, 'dependency' => $dependency, 'payload' => $payload->value]);
            $saved = $db->row('SELECT source_revision FROM ' . $db->table('derived_payloads') . ' WHERE tenant_id=:tenant AND id=:id',
                ['tenant' => $source->tenant->value, 'id' => $id]);
            if ($saved === null || Connection::number($saved, 'source_revision') !== $revision) {
                throw new IntegrityConflict('Derived payload revision conflict');
            }
        });
    }

    public function read(ExecutionContext $context, Ref $source, string $dependency, Ref $payload): string
    {
        $this->authorize($context, $source, $dependency, 'read');
        $source->tenant->assertSame($payload->tenant);
        $db = $this->environment->db;
        $row = $db->row('SELECT source_revision FROM ' . $db->table('derived_payloads') . '
            WHERE tenant_id=:tenant AND session_id=:session AND source_ref=:source AND dependency=:dependency AND payload_id=:payload',
            ['tenant' => $source->tenant->value, 'session' => $context->session->value,
                'source' => $source->value, 'dependency' => $dependency, 'payload' => $payload->value]);
        if ($row === null) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        $revision = Connection::number($row, 'source_revision');
        $this->current($source, $revision);
        $bytes = $this->payloads->dereference($context, $payload);
        $this->authorize($context, $source, $dependency, 'read');
        $this->current($source, $revision);
        return $bytes;
    }

    /** Bounded work for the existing scheduler. No receipt until actual physical deletion of every registered payload. */
    public function cleanup(ExecutionContext $context, Ref $source, string $dependency, int $watermark,
        string $key, PayloadHash $hash, int $limit = 100, ?string $after = null): Ref
    {
        $this->authorize($context, $source, $dependency, 'cleanup');
        if ($watermark < 1 || $key === '' || strlen($key) > 128 || $limit < 1 || $limit > 1000) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        $parameters = ['tenant' => $source->tenant->value, 'id' => $key, 'session' => $context->session->value,
            'source' => $source->value, 'dependency' => $dependency, 'watermark' => $watermark, 'hash' => substr($hash->value, 8)];
        $db = $this->environment->db;
        $saved = $db->row('SELECT * FROM ' . $db->table('derived_cleanup_receipts') . ' WHERE tenant_id=:tenant AND id=:id',
            ['tenant' => $parameters['tenant'], 'id' => $key]);
        if ($saved !== null) {
            $this->matchingReceipt($saved, $parameters);
            if ($saved['completed_at'] !== null) {
                return new Ref($source->tenant, 'derived:cleanup:' . $key);
            }
        }
        $fence = $db->row('SELECT closed,watermark FROM ' . $db->table('source_fences') . ' WHERE tenant_id=:tenant AND id=:id',
            ['tenant' => $source->tenant->value, 'id' => $source->value]);
        if ($fence === null || $fence['closed'] !== true || Connection::number($fence, 'watermark') < $watermark) {
            throw new ContractViolation(ErrorCode::ManualResolutionRequired);
        }
        // Bind the operation identity durably before the first irreversible IO.
        $db->transaction(function () use ($db, $parameters): void {
            $db->execute('INSERT INTO ' . $db->table('derived_cleanup_receipts') . '
                (tenant_id,id,session_id,source_ref,dependency,watermark,payload_hash)
                VALUES (:tenant,:id,:session,:source,:dependency,:watermark,:hash) ON CONFLICT DO NOTHING', $parameters);
            $saved = $db->row('SELECT * FROM ' . $db->table('derived_cleanup_receipts') . ' WHERE tenant_id=:tenant AND id=:id',
                ['tenant' => $parameters['tenant'], 'id' => $parameters['id']]);
            if ($saved === null) {
                throw new ContractViolation(ErrorCode::ManualResolutionRequired);
            }
            $this->matchingReceipt($saved, $parameters);
        });
        $scope = ['tenant' => $source->tenant->value, 'session' => $context->session->value,
            'source' => $source->value, 'dependency' => $dependency];
        $where = 'd.tenant_id=:tenant AND d.session_id=:session AND d.source_ref=:source AND d.dependency=:dependency';
        foreach ($this->scan($context, $source, $dependency, $limit, $after) as $ref) {
            $this->payloads->purge($context, $ref);
        }
        $this->authorize($context, $source, $dependency, 'cleanup');
        $db->transaction(function () use ($db, $where, $scope, $parameters): void {
            $row = $db->row('SELECT count(*) AS total,count(*) FILTER (WHERE p.purged_at IS NULL) AS remaining
                FROM ' . $db->table('derived_payloads') . ' d JOIN ' . $db->table('payload_refs') . ' p
                ON p.tenant_id=d.tenant_id AND p.id=d.payload_id WHERE ' . $where, $scope);
            if ($row === null || Connection::number($row, 'total') === 0 || Connection::number($row, 'remaining') !== 0) {
                throw new ContractViolation(ErrorCode::ManualResolutionRequired);
            }
            $db->execute('UPDATE ' . $db->table('derived_cleanup_receipts') . '
                SET version=version+1,erased_count=:count,completed_at=clock_timestamp()
                WHERE tenant_id=:tenant AND id=:id AND completed_at IS NULL',
                ['tenant' => $parameters['tenant'], 'id' => $parameters['id'], 'count' => Connection::number($row, 'total')]);
            $saved = $db->row('SELECT * FROM ' . $db->table('derived_cleanup_receipts') . ' WHERE tenant_id=:tenant AND id=:id',
                ['tenant' => $parameters['tenant'], 'id' => $parameters['id']]);
            if ($saved === null) {
                throw new ContractViolation(ErrorCode::ManualResolutionRequired);
            }
            $this->matchingReceipt($saved, $parameters);
        });
        return new Ref($source->tenant, 'derived:cleanup:' . $key);
    }

    /** @return list<Ref> Metadata only. Advance after the last id to revisit holds without starving later pages. */
    public function scan(ExecutionContext $context, Ref $source, string $dependency, int $limit = 100, ?string $after = null): array
    {
        $this->authorize($context, $source, $dependency, 'cleanup');
        if ($limit < 1 || $limit > 1000) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        $db = $this->environment->db;
        $parameters = ['tenant' => $source->tenant->value, 'session' => $context->session->value,
            'source' => $source->value, 'dependency' => $dependency];
        if ($after !== null) { $parameters['after'] = $after; }
        $rows = $db->execute('SELECT d.payload_id FROM ' . $db->table('derived_payloads') . ' d JOIN ' . $db->table('payload_refs') . ' p
            ON p.tenant_id=d.tenant_id AND p.id=d.payload_id
            WHERE d.tenant_id=:tenant AND d.session_id=:session AND d.source_ref=:source AND d.dependency=:dependency
            AND p.purged_at IS NULL' . ($after === null ? '' : ' AND d.payload_id>:after') . ' ORDER BY d.payload_id LIMIT ' . $limit,
            $parameters)->fetchAll(PDO::FETCH_COLUMN);
        $refs = [];
        foreach ($rows as $row) {
            if (!is_string($row)) { throw new ContractViolation(ErrorCode::InvalidInput); }
            $refs[] = new Ref($source->tenant, $row);
        }
        return $refs;
    }

    private function authorize(ExecutionContext $context, Ref $source, string $dependency, string $operation): void
    {
        $context->session->tenant->assertSame($source->tenant);
        if (!in_array($dependency, self::DEPENDENCIES, true)) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        $this->environment->authorize($context);
        ($this->authorizeSource)($context, $source, $operation);
    }

    private function current(Ref $source, int $revision): void
    {
        $db = $this->environment->db;
        $row = $db->row('SELECT closed,watermark FROM ' . $db->table('source_fences') . ' WHERE tenant_id=:tenant AND id=:id',
            ['tenant' => $source->tenant->value, 'id' => $source->value]);
        if ($row === null || $row['closed'] !== false || Connection::number($row, 'watermark') !== $revision) {
            throw new ContractViolation(ErrorCode::Cancelled);
        }
    }

    /**
     * @param array<string,mixed> $saved
     * @param array<string,string|int> $expected
     */
    private function matchingReceipt(array $saved, array $expected): void
    {
        foreach (['session_id' => 'session', 'source_ref' => 'source', 'dependency' => 'dependency', 'payload_hash' => 'hash'] as $column => $parameter) {
            if (Connection::text($saved, $column) !== $expected[$parameter]) {
                throw new IntegrityConflict('Derived cleanup identity conflict');
            }
        }
        if (Connection::number($saved, 'watermark') !== $expected['watermark']) {
            throw new IntegrityConflict('Derived cleanup watermark conflict');
        }
    }
}
