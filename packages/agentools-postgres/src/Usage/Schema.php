<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Usage;

use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use PDO;

/** Explicit additive installation; no provisioning and no production/test lifecycle. */
final class Schema
{
    public static function install(PDO $pdo, string $schema): void
    {
        $db = new Connection($pdo,$schema);
        $usage = file_get_contents(__DIR__.'/schema.sql');
        $budget = file_get_contents(__DIR__.'/../Budget/schema.sql');
        if ($usage === false || $budget === false) { throw new \RuntimeException('Accounting schema missing'); }
        $sql = $usage."\n".$budget; $hash = hash('sha256',$sql);
        $db->transaction(static function () use ($db,$pdo,$schema,$sql,$hash): void {
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))',['key'=>$schema.':accounting-schema']);
            $db->execute('CREATE TABLE IF NOT EXISTS '.$db->table('accounting_schema_versions').' (revision integer PRIMARY KEY, source_hash text NOT NULL)');
            $before = $db->row('SELECT source_hash FROM '.$db->table('accounting_schema_versions').' WHERE revision=1');
            if ($before !== null) {
                if (Connection::text($before,'source_hash') !== $hash) { throw new IntegrityConflict('Accounting schema revision conflict'); }
                return;
            }
            $pdo->exec(str_replace('{{schema}}','"'.$schema.'"',$sql));
            $db->execute('INSERT INTO '.$db->table('accounting_schema_versions').' VALUES(1,:hash)',['hash'=>$hash]);
        });
    }
}
