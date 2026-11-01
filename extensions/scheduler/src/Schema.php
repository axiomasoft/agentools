<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

/** Explicit extension installation after the owning platform migrations. Never runs at package boot. */
final class Schema
{
    public static function install(\PDO $pdo, string $schema): void
    {
        self::assertIdle($pdo);
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1
            || in_array($schema, ['public','information_schema'], true) || str_starts_with($schema, 'pg_')) {
            throw new \InvalidArgumentException('Explicit idle application schema required');
        }
        $sql = file_get_contents(__DIR__.'/schema.sql');
        if ($sql === false) { throw new InspectionFailure('schema_missing'); }
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET LOCAL search_path TO "'.$schema.'",pg_catalog');
            $pdo->exec($sql); $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw new InspectionFailure('schema_install_failed');
        }
    }
    private static function assertIdle(\PDO $pdo): void
    {
        if ($pdo->inTransaction()) { throw new \InvalidArgumentException('Explicit idle application schema required'); }
    }
}
