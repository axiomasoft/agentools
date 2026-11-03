<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Qualification;
use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use PDO;

/** Explicit schema-owner setup only; never runs during a selection or package boot. */
final class Schema
{
    public static function install(PDO $pdo, string $schema): void
    {
        $db = new Connection($pdo,$schema); $sql = file_get_contents(__DIR__.'/schema.sql');
        if ($sql === false) { throw new \RuntimeException('Qualification schema missing'); }
        $hash = hash('sha256',$sql);
        $db->transaction(static function () use ($db,$pdo,$schema,$sql,$hash): void {
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))',['key'=>$schema.':qualification-schema']);
            $db->execute('CREATE TABLE IF NOT EXISTS '.$db->table('qualification_schema_versions').' (revision integer PRIMARY KEY, source_hash text NOT NULL)');
            $row = $db->row('SELECT source_hash FROM '.$db->table('qualification_schema_versions').' WHERE revision=1');
            if ($row !== null) {
                if (Connection::text($row,'source_hash') !== $hash) { throw new IntegrityConflict('Qualification schema revision conflict'); }
                return;
            }
            $pdo->exec(str_replace('{{schema}}','"'.$schema.'"',$sql));
            $db->execute('INSERT INTO '.$db->table('qualification_schema_versions').' VALUES(1,:hash)',['hash'=>$hash]);
        });
    }
}
