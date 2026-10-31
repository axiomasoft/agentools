<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres;

use PDO;
use Throwable;

/** Additive, checksum-pinned migrations. Deployment owns schema/role provisioning. */
final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $schema)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $schema) !== 1
            || in_array($schema, ['public', 'information_schema'], true) || str_starts_with($schema, 'pg_')) {
            throw new MigrationException('An explicit application PostgreSQL schema is required');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @return list<string> Applied versions; an identical reapply is empty. */
    public function migrate(?string $directory = null): array
    {
        if ($this->pdo->inTransaction()) {
            throw new MigrationException('Migration cannot join a caller transaction');
        }
        $files = glob(($directory ?? dirname(__DIR__) . '/migrations') . '/*.sql');
        if ($files === false || $files === []) {
            throw new MigrationException('Required migration files are missing');
        }
        sort($files, SORT_STRING);
        $migrations = [];
        foreach ($files as $file) {
            $version = basename($file);
            $sql = file_get_contents($file);
            if (preg_match('/\A[0-9]{4}_[a-z0-9_]+\.sql\z/D', $version) !== 1 || $sql === false || trim($sql) === '') {
                throw new MigrationException('Invalid migration input');
            }
            $migrations[$version] = ['sql' => $sql, 'hash' => hash('sha256', $sql)];
        }
        $this->pdo->beginTransaction();
        try {
            // Serialize the history check and DDL across processes, scoped to this database/schema.
            $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(current_database() || :schema, 0))');
            $lock->execute(['schema' => '.' . $this->schema]);
            $exists = $this->pdo->prepare('SELECT 1 FROM pg_namespace WHERE nspname = :schema');
            $exists->execute(['schema' => $this->schema]);
            if ($exists->fetchColumn() === false) {
                throw new MigrationException('Application schema must be provisioned explicitly');
            }
            $this->pdo->exec('SET LOCAL search_path TO "' . $this->schema . '", pg_catalog');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS platform_migrations (
                version text PRIMARY KEY, checksum text NOT NULL CHECK (checksum ~ \'^[0-9a-f]{64}$\'),
                applied_at timestamptz NOT NULL DEFAULT clock_timestamp())');
            $history = $this->pdo->query('SELECT version, checksum FROM platform_migrations ORDER BY version');
            if ($history === false) {
                throw new MigrationException('Migration history unavailable');
            }
            $applied = $history->fetchAll(PDO::FETCH_KEY_PAIR);
            if (array_keys($applied) !== array_slice(array_keys($migrations), 0, count($applied))) {
                throw new MigrationException('Migration history is not a known contiguous prefix');
            }
            foreach ($applied as $version => $hash) {
                if ($hash !== $migrations[$version]['hash']) {
                    throw new MigrationException('Applied migration checksum conflict');
                }
            }
            $new = [];
            foreach ($migrations as $version => $migration) {
                if (array_key_exists($version, $applied)) {
                    continue;
                }
                $this->pdo->exec($migration['sql']);
                $insert = $this->pdo->prepare('INSERT INTO platform_migrations(version, checksum) VALUES (:version, :hash)');
                $insert->execute(['version' => $version, 'hash' => $migration['hash']]);
                $new[] = $version;
            }
            $this->pdo->commit();
            return $new;
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            if ($error instanceof MigrationException) {
                throw $error;
            }
            // Raw SQL and connection diagnostics are not a client-facing error contract.
            throw new MigrationException('Migration failed and was rolled back', 0, $error);
        }
    }
}
