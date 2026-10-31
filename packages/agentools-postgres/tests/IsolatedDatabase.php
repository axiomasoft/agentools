<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests;

use PDO;
use RuntimeException;

/** Test-only lifecycle. An environment flag alone is never proof of isolation. */
final class IsolatedDatabase
{
    public readonly PDO $pdo;
    private readonly string $database;
    private readonly string $owner;

    public function __construct(?string $dsn = null, ?string $owner = null, ?string $password = null)
    {
        $dsn ??= (string) getenv('AGENTOOLS_TEST_PG_DSN');
        $owner ??= (string) getenv('AGENTOOLS_TEST_PG_USER');
        $password ??= (string) getenv('AGENTOOLS_TEST_PG_PASSWORD');
        if (preg_match('/\Apgsql:host=(127\.0\.0\.1|localhost|::1);port=([0-9]{1,5});dbname=(agentools_test_[a-z0-9_]+)\z/D', $dsn, $match) !== 1
            || $owner === '' || $password === '') {
            throw new RuntimeException('Only explicit loopback isolated test credentials are accepted');
        }
        $this->database = $match[3];
        $this->owner = $owner;
        $this->pdo = new PDO($dsn, $owner, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->verify();
    }

    /** @return array{engine: string, isolated: true, database: string, owner: string, allow_marker_verified: true, server_version: string} */
    public function verify(): array
    {
        $statement = $this->pdo->query("SELECT current_database() AS db, current_user AS usr,
            pg_get_userbyid(d.datdba) AS owner, shobj_description(d.oid, 'pg_database') AS marker,
            r.rolsuper, r.rolcreatedb, r.rolcreaterole, current_setting('server_version') AS server_version
            FROM pg_database d JOIN pg_roles r ON r.rolname = current_user WHERE d.datname = current_database()");
        if ($statement === false) {
            throw new RuntimeException('Database isolation metadata unavailable');
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['db'] !== $this->database || $row['usr'] !== $this->owner
            || $row['owner'] !== $this->owner || $row['rolsuper'] || $row['rolcreatedb'] || $row['rolcreaterole']
            || $row['marker'] !== 'agentools:isolation:v1:' . $this->database . ':' . $this->owner
            || !is_string($row['server_version'])) {
            throw new RuntimeException('Database owner/name/allow marker or restricted role verification failed');
        }
        return ['engine' => 'postgresql', 'isolated' => true, 'database' => $this->database,
            'owner' => $this->owner, 'allow_marker_verified' => true, 'server_version' => $row['server_version']];
    }

    public function createSchema(string $schema): void
    {
        $this->verify();
        self::schemaName($schema);
        $this->pdo->exec('CREATE SCHEMA "' . $schema . '"');
        $this->pdo->exec('SET search_path TO "' . $schema . '", pg_catalog');
    }

    public function dropSchema(string $schema): void
    {
        $this->verify();
        self::schemaName($schema);
        $stmt = $this->pdo->prepare('SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname = :schema');
        $stmt->execute(['schema' => $schema]);
        if ($stmt->fetchColumn() !== $this->owner) {
            throw new RuntimeException('Cleanup schema owner mismatch');
        }
        $this->pdo->exec('DROP SCHEMA "' . $schema . '" CASCADE');
    }

    private static function schemaName(string $schema): void
    {
        if (preg_match('/\Ap3_[a-f0-9]{24}\z/D', $schema) !== 1) {
            throw new RuntimeException('Lifecycle schema is outside the run namespace');
        }
    }
}
