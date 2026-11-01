<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';require_once __DIR__.'/Process.php';

use AgenToolsPlatform\Postgres\Tests\Summaries\{Fixture,Process,Proof};
use AgenToolsPlatform\Tests\Harness as H;

Proof::run(['summary.crash-restart'=>static function(): void {
    $f=new Fixture();$lock=random_int(100000,2000000000);try {
        $schema='"'.$f->schema.'"';$f->db->execute('SELECT pg_advisory_lock(:key)',['key'=>$lock]);
        $f->database->pdo->exec('CREATE FUNCTION '.$schema.'.pause_summary() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_advisory_xact_lock('.$lock.'); RETURN NEW; END $$');
        $f->database->pdo->exec('CREATE TRIGGER pause_summary BEFORE INSERT ON '.$schema.'.summary_versions FOR EACH ROW EXECUTE FUNCTION '.$schema.'.pause_summary()');
        $worker=new Process($f->schema);$worker->go();$blocked=false;$deadline=microtime(true)+10;
        do {
            $row=$f->db->row("SELECT wait_event_type FROM pg_stat_activity WHERE pid=:pid AND query LIKE '%summary_versions%'",['pid'=>$worker->backend]);
            if ($row!==null && $row['wait_event_type']==='Lock') { $blocked=true;break; }usleep(10000);
        } while (microtime(true)<$deadline);
        $beforeKill=$worker->kill();H::check($blocked && $beforeKill['signaled']===true && $beforeKill['signal']===9);
        $f->db->execute('SELECT pg_advisory_unlock(:key)',['key'=>$lock]);$f->database->pdo->exec('DROP TRIGGER pause_summary ON '.$schema.'.summary_versions');
        H::check($f->count('summary_versions')===0 && $f->count('derived_refs')===0 && $f->count('history_source_pins')===0);
        $restart=new Process($f->schema,'hold');$restart->go();$committed=$restart->line();$afterKill=$restart->kill();
        H::check($committed['outcome']==='appended' && $afterKill['signaled']===true && $afterKill['signal']===9);
        $fresh=new Fixture($f->schema);$summary=$fresh->summary();$fresh->repo->append($fresh->h->core->context,$summary,0);
        H::check($fresh->count('summary_versions')===1 && $fresh->repo->head($fresh->h->core->context,$fresh->h->branch)?->hash->value===$committed['hash']);
        Proof::$observations['crash_restart']=['before_commit'=>$beforeKill,'after_commit'=>$afterKill,'rolled_back'=>true,'replay_versions'=>1,'same_hash'=>true];
    } finally { $f->db->execute('SELECT pg_advisory_unlock(:key)',['key'=>$lock]);$f->close(); }
}]);
