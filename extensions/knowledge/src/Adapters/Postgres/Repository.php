<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Adapters\Postgres;

use AgenToolsPlatform\Knowledge\{Decision, Integrity, KnowledgeRepository, Material, MaterialAccessPolicy, Release, Scope};
use PDO;

/** Technical projection only. Dedicated PDO connection/schema; no source-byte ownership. */
final readonly class Repository implements KnowledgeRepository
{
    public function __construct(private PDO $pdo, private string $schema, private MaterialAccessPolicy $access)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $schema) !== 1) {
            throw new \InvalidArgumentException('PostgreSQL and safe schema required');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** Host-owned setup only; never implicitly called by ingest/read. */
    public function install(): void
    {
        $this->transaction(function (): void {
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            if ($sql === false) { throw new \RuntimeException('Schema missing'); }
            $this->pdo->exec($sql);
        });
    }

    public function publish(Scope $scope, Release $release, ?Material $previous = null): Release
    {
        $release->assertPublishable();
        $material = $release->material;
        $this->authorize($scope, $material, 'publish');
        if ($previous !== null) {
            if ($previous->tenant !== $material->tenant || $previous->id !== $material->id || $previous->version === $material->version) {
                throw new \DomainException('Correction requires a distinct version of the same tenant material');
            }
            $this->authorize($scope, $previous, 'correct');
        }
        return $this->transaction(function () use ($release, $material, $previous): Release {
            $this->lock($material);
            $this->requireActive($material);
            // A material version is immutable even when published under another release/corpus ID.
            $row = $this->row('SELECT payload, content_hash FROM knowledge_releases WHERE tenant=? AND material_id=? AND source_version=? LIMIT 1',
                [$material->tenant, $material->id, $material->version]);
            if ($row !== null) {
                $existing = $this->decode($row);
                if (Integrity::digest($existing->material) !== Integrity::digest($material)) {
                    throw new \DomainException('Material version hash conflict');
                }
            }
            $this->execute('INSERT INTO knowledge_releases (tenant,corpus,release_id,material_id,source_version,source_hash,content_hash,payload) VALUES (?,?,?,?,?,?,?,?) ON CONFLICT DO NOTHING',
                [$material->tenant, $release->corpus, $release->id, $material->id, $material->version, $material->sourceHash, $release->hash(), $release->json()]);
            $stored = $this->find($material->tenant, $release->corpus, $release->id);
            if ($stored === null || !hash_equals($stored->hash(), $release->hash())) { throw new \DomainException('Release identity hash conflict'); }
            if ($previous !== null) { $this->invalidateLocal($previous, 'corrected:' . $material->version); }
            return $stored;
        });
    }

    public function get(Scope $scope, string $corpus, string $id): ?Release
    {
        $release = $this->transaction(fn(): ?Release => $this->find($scope->tenant, $corpus, $id));
        if ($release === null) { return null; }
        $this->authorize($scope, $release->material, 'read');
        $this->transaction(function () use ($release): void {
            $this->lock($release->material); $this->requireActive($release->material);
        });
        return $release;
    }

    public function invalidate(Scope $scope, Material $material, string $reason): void
    {
        Integrity::text($reason); $this->authorize($scope, $material, 'invalidate');
        $this->transaction(function () use ($material, $reason): void {
            $this->lock($material); $this->invalidateLocal($material, $reason);
        });
    }

    public function link(Scope $scope, Release $release, string $kind, string $ref): void
    {
        Integrity::text($ref);
        if (!in_array($kind, ['bundle', 'snapshot', 'cache'], true)) { throw new \InvalidArgumentException('Unknown dependency kind'); }
        $this->authorize($scope, $release->material, 'link');
        $this->transaction(function () use ($release, $kind, $ref): void {
            $this->lock($release->material); $this->requireActive($release->material);
            $stored = $this->find($release->material->tenant, $release->corpus, $release->id);
            if ($stored === null || $stored->hash() !== $release->hash()) { throw new \DomainException('Unpublished dependency'); }
            $this->execute('INSERT INTO knowledge_dependencies (tenant,corpus,release_id,kind,dependent_ref) VALUES (?,?,?,?,?) ON CONFLICT DO NOTHING',
                [$release->material->tenant, $release->corpus, $release->id, $kind, $ref]);
            $row = $this->row('SELECT corpus,release_id FROM knowledge_dependencies WHERE tenant=? AND kind=? AND dependent_ref=?',
                [$release->material->tenant, $kind, $ref]);
            if ($row === null || $row['corpus'] !== $release->corpus || $row['release_id'] !== $release->id) {
                throw new \DomainException('Dependency identity conflict');
            }
        });
    }

    public function dependencyUsable(Scope $scope, Release $release, string $kind, string $ref): bool
    {
        $this->authorize($scope, $release->material, 'read');
        return $this->transaction(function () use ($release, $kind, $ref): bool {
            $this->lock($release->material);
            if ($this->invalidated($release->material)) { return false; }
            $stored = $this->find($release->material->tenant, $release->corpus, $release->id);
            if ($stored === null || !hash_equals($stored->hash(), $release->hash())) { return false; }
            $row = $this->row('SELECT corpus,release_id FROM knowledge_dependencies WHERE tenant=? AND kind=? AND dependent_ref=?',
                [$release->material->tenant, $kind, $ref]);
            return $row !== null && $row['corpus'] === $release->corpus && $row['release_id'] === $release->id;
        });
    }

    private function authorize(Scope $scope, Material $material, string $operation): void
    {
        $this->idle();
        if (!$scope->matches($material) || $this->access->check($scope, $material, $operation) !== Decision::Allowed) {
            throw new \DomainException('Current owner access denied or unknown');
        }
    }

    private function idle(): void
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Knowledge requires its own transaction boundary'); }
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function transaction(\Closure $operation): mixed
    {
        $this->idle(); $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SET LOCAL search_path TO "' . $this->schema . '", pg_catalog');
            $result = $operation(); $this->pdo->commit(); return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private function lock(Material $material): void
    {
        $this->execute('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            [Integrity::digest([$material->tenant, $material->id])]);
    }

    private function requireActive(Material $material): void
    {
        if ($this->invalidated($material)) { throw new \DomainException('Source version invalidated'); }
    }

    private function invalidated(Material $material): bool
    {
        return $this->row('SELECT reason FROM knowledge_invalidations WHERE tenant=? AND material_id=? AND source_version=?',
            [$material->tenant, $material->id, $material->version]) !== null;
    }

    private function invalidateLocal(Material $material, string $reason): void
    {
        $this->execute('INSERT INTO knowledge_invalidations (tenant,material_id,source_version,reason) VALUES (?,?,?,?) ON CONFLICT DO NOTHING',
            [$material->tenant, $material->id, $material->version, $reason]);
    }

    private function find(string $tenant, string $corpus, string $id): ?Release
    {
        $row = $this->row('SELECT payload,content_hash FROM knowledge_releases WHERE tenant=? AND corpus=? AND release_id=?', [$tenant, $corpus, $id]);
        if ($row === null) { return null; }
        $release = $this->decode($row);
        if ($release->material->tenant !== $tenant || $release->corpus !== $corpus || $release->id !== $id) {
            throw new \UnexpectedValueException('Stored release scope mismatch');
        }
        return $release;
    }

    /** @param array<string,mixed> $row */
    private function decode(array $row): Release
    {
        if (!is_string($row['payload']) || !is_string($row['content_hash'])) { throw new \UnexpectedValueException('Invalid stored release'); }
        return Release::restore($row['payload'], $row['content_hash']);
    }

    /**
     * @param list<string> $parameters
     * @return array<string,mixed>|null
     */
    private function row(string $sql, array $parameters): ?array
    {
        $row = $this->execute($sql, $parameters)->fetch(PDO::FETCH_ASSOC);
        if ($row === false) { return null; }
        /** @var array<string,mixed> $row */
        return $row;
    }

    /** @param list<string> $parameters */
    private function execute(string $sql, array $parameters): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql); $statement->execute($parameters); return $statement;
    }
}
