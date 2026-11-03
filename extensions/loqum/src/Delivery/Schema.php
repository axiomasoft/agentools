<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Explicit opt-in technical schema; no owner transcript, audio or business facts. */
final class Schema
{
    public static function install(Connection $db): void
    {
        $sql=[
            'CREATE TABLE '.$db->table('voice_responses').' (tenant_id text NOT NULL,id text NOT NULL,hash text NOT NULL,manifest jsonb NOT NULL,
                epoch bigint NOT NULL CHECK(epoch>0),stopped boolean NOT NULL DEFAULT false,revoked boolean NOT NULL DEFAULT false,
                retention_receipt_ref text,PRIMARY KEY(tenant_id,id))',
            'CREATE TABLE '.$db->table('voice_segments').' (tenant_id text NOT NULL,id text NOT NULL,response_id text NOT NULL,
                sequence bigint NOT NULL CHECK(sequence>0),hash text NOT NULL,manifest jsonb NOT NULL,
                speech_bytes bigint NOT NULL CHECK(speech_bytes>0),display_bytes bigint NOT NULL CHECK(display_bytes>0),
                confirmed bigint NOT NULL DEFAULT 0,estimated bigint NOT NULL DEFAULT 0,displayed bigint NOT NULL DEFAULT 0,
                display_state text NOT NULL DEFAULT \'pending\' CHECK(display_state IN (\'pending\',\'unknown\',\'displayed\')),
                state text NOT NULL DEFAULT \'pending\' CHECK(state IN (\'pending\',\'sending\',\'unknown\',\'failed\',\'interrupted\',\'delivered\')),
                attempt_offset bigint NOT NULL DEFAULT 0,artifact_ref text,synthesized boolean NOT NULL DEFAULT false,
                PRIMARY KEY(tenant_id,id),UNIQUE(tenant_id,response_id,sequence),
                FOREIGN KEY(tenant_id,response_id) REFERENCES '.$db->table('voice_responses').'(tenant_id,id),
                CHECK(confirmed BETWEEN 0 AND speech_bytes),CHECK(estimated BETWEEN 0 AND speech_bytes),
                CHECK(displayed BETWEEN 0 AND display_bytes),CHECK(attempt_offset BETWEEN 0 AND speech_bytes))',
            'CREATE TABLE '.$db->table('voice_receipts').' (tenant_id text NOT NULL,id text NOT NULL,segment_id text NOT NULL,
                hash text NOT NULL,kind text NOT NULL CHECK(kind IN (\'speech\',\'display\')),manifest jsonb NOT NULL,
                PRIMARY KEY(tenant_id,id),FOREIGN KEY(tenant_id,segment_id) REFERENCES '.$db->table('voice_segments').'(tenant_id,id))',
            'CREATE TABLE '.$db->table('voice_commands').' (tenant_id text NOT NULL,id text NOT NULL,response_id text NOT NULL,
                hash text NOT NULL,manifest jsonb NOT NULL,state text NOT NULL DEFAULT \'pending\'
                CHECK(state IN (\'pending\',\'executing\',\'unknown\',\'executed\',\'revoked\')),receipt_ref text,
                PRIMARY KEY(tenant_id,id),FOREIGN KEY(tenant_id,response_id) REFERENCES '.$db->table('voice_responses').'(tenant_id,id))',
        ];
        $db->transaction(function() use($db,$sql):void {
            $db->execute('SELECT pg_advisory_xact_lock(hashtext(:lock))',['lock'=>$db->table('voice_schema')]);
            $db->execute('CREATE TABLE IF NOT EXISTS '.$db->table('voice_schema').' (revision integer PRIMARY KEY,hash text NOT NULL)');
            $hash=hash('sha256',implode("\n",$sql));
            $saved=$db->row('SELECT hash FROM '.$db->table('voice_schema').' WHERE revision=1');
            if ($saved!==null) {
                if (Connection::text($saved,'hash')!==$hash) { throw new IntegrityConflict('Voice schema revision conflict'); }
                return;
            }
            foreach($sql as $statement) { $db->execute($statement); }
            $db->execute('INSERT INTO '.$db->table('voice_schema').' VALUES(1,:hash)',['hash'=>$hash]);
        });
    }
}
