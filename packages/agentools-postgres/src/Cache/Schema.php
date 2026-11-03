<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Cache;
use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use PDO;

final class Schema
{
    public static function install(PDO $pdo,string $schema): void
    {
        $db=new Connection($pdo,$schema); $sql=file_get_contents(__DIR__.'/schema.sql');
        if ($sql===false) { throw new \RuntimeException('Cache schema missing'); } $hash=hash('sha256',$sql);
        $db->transaction(static function () use ($db,$pdo,$schema,$sql,$hash): void {
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))',['key'=>$schema.':model-cache-schema']);
            $db->execute('CREATE TABLE IF NOT EXISTS '.$db->table('model_cache_schema').' (revision integer PRIMARY KEY,source_hash text NOT NULL)');
            $old=$db->row('SELECT source_hash FROM '.$db->table('model_cache_schema').' WHERE revision=1');
            if ($old!==null) { if ($old['source_hash']!==$hash) { throw new IntegrityConflict('Cache schema revision conflict'); } return; }
            $pdo->exec(str_replace('{{schema}}','"'.$schema.'"',$sql));
            $db->execute('INSERT INTO '.$db->table('model_cache_schema').' VALUES(1,:hash)',['hash'=>$hash]);
        });
    }
}
