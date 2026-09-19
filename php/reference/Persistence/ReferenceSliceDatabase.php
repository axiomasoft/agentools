<?php

declare(strict_types=1);

namespace AgenTools\Reference\Persistence;

use PDO;
use RuntimeException;

/** SQLite connection for the standalone reference slice (INT-002 §4). */
final class ReferenceSliceDatabase
{
    private const int SCHEMA_VERSION = 1;

    private function __construct(private readonly PDO $pdo)
    {
    }

    public static function open(string $path = ':memory:', bool $runMigrations = true): self
    {
        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create SQLite directory: ' . $directory);
            }
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        $database = new self($pdo);
        if ($runMigrations) {
            $database->migrateUp();
        }

        return $database;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function migrateUp(): void
    {
        if ($this->currentVersion() >= self::SCHEMA_VERSION) {
            return;
        }

        $sql = file_get_contents(__DIR__ . '/../migrations/001_reference_slice_up.sql');
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('Reference slice migration is missing.');
        }

        $this->pdo->exec($sql);
        $statement = $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)');
        $statement->execute([
            'version' => self::SCHEMA_VERSION,
            'applied_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function migrateDown(): void
    {
        if ($this->currentVersion() === 0) {
            return;
        }

        $sql = file_get_contents(__DIR__ . '/../migrations/001_reference_slice_down.sql');
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('Reference slice rollback migration is missing.');
        }

        $this->pdo->exec($sql);
    }

    public function currentVersion(): int
    {
        $tablesStatement = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'");
        if ($tablesStatement === false) {
            return 0;
        }
        $tables = $tablesStatement->fetchAll();
        if ($tables === []) {
            return 0;
        }

        $versionStatement = $this->pdo->query('SELECT MAX(version) AS version FROM schema_migrations');
        if ($versionStatement === false) {
            return 0;
        }
        $version = $versionStatement->fetchColumn();
        return is_numeric($version) ? (int) $version : 0;
    }
}
