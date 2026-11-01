<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref,RunId,TenantId};
use AgenToolsPlatform\Core\Context\History\{DerivedKind,WorkingState};
use AgenToolsPlatform\Postgres\Summaries\Schema;
use AgenToolsPlatform\Postgres\Tests\Summaries\{Fixture,Proof};
use AgenToolsPlatform\Tests\Harness as H;

Proof::run([
    'summary.durable-metadata'=>static function(): void {
        $f=new Fixture();try {
            $h=$f->h;$ctx=$h->core->context;$s=$f->summary();$f->repo->saveState($ctx,$h->state,0);$f->repo->saveState($ctx,$h->state,0);
            $f->repo->append($ctx,$s,0);$f->repo->append($ctx,$s,0);
            H::check($f->repo->head($ctx,$h->branch)?->toArray()===$s->toArray() && $f->count('summary_versions')===1);
            H::check($f->repo->state($ctx,$h->branch)?->toArray()===$h->state->toArray() && $f->count('working_versions')===1);
            $row=$f->db->row('SELECT payload FROM '.$f->db->table('summary_versions'));
            H::check($row!==null && !str_contains(\AgenToolsPlatform\Postgres\Runtime\Connection::text($row,'payload'),'exact turn') && !str_contains(\AgenToolsPlatform\Postgres\Runtime\Connection::text($row,'payload'),'baseline'));
            H::check($f->count('history_source_pins')===10);
            Proof::$observations['metadata_only']=['summary_versions'=>1,'source_pins'=>10,'transcript_columns'=>0];
        } finally { $f->close(); }
    },
    'summary.monotone-integrity'=>static function(): void {
        $f=new Fixture();try {
            $ctx=$f->h->core->context;$one=$f->summary();$two=$f->summary(11,2);$f->repo->append($ctx,$one,0);$f->repo->append($ctx,$two,1);
            H::rejects(static fn()=> $f->repo->append($ctx,$f->summary(9,3),2),ContractViolation::class,ErrorCode::OptimisticConflict);
            H::rejects(static fn()=> $f->repo->append($ctx,$f->summary(11,2,'different hash'),1),ContractViolation::class,ErrorCode::IntegrityConflict);
            H::check($f->count('summary_versions')===2 && $f->repo->head($ctx,$f->h->branch)?->hash->value===$two->hash->value);
            $source=$one->sources->messages[0];
            $different=new \AgenToolsPlatform\Core\Context\History\MessageRef($source->ref,$source->branch,$source->turn,$source->version,$f->h->core->hasher->hash($source->ref->tenant,'history-message','different source hash'));
            H::rejects(static fn()=> $f->repo->register($ctx,$f->h->core->ref('bad-source-cache'),DerivedKind::ContextCache,[$different]),ContractViolation::class,ErrorCode::IntegrityConflict);
            $run=new RunId($f->h->core->tenant,'run:corrected');$f->repo->register($ctx,$two->ref,DerivedKind::Summary,$two->sources->messages,[$run]);
            $f->repo->invalidate($ctx,$source,'source_corrected');
            H::check(!$f->repo->usable($ctx,$two->ref) && $f->repo->markers($ctx,$run)[0]['reason']==='source_corrected');
            $corrected=new \AgenToolsPlatform\Core\Context\History\MessageRef($source->ref,$source->branch,$source->turn,'v2',$different->hash);
            $f->repo->register($ctx,$f->h->core->ref('corrected-cache'),DerivedKind::ContextCache,[$corrected]);
            H::check($f->repo->usable($ctx,$f->h->core->ref('corrected-cache')));

            $f->repo->saveState($ctx,$f->h->state,0);
            $next=new WorkingState($f->h->state->value,2,$f->h->state->acceptance,$f->h->state->expiresAt,'changed retention');
            H::rejects(static fn()=> $f->repo->saveState($ctx,$next,0),ContractViolation::class,ErrorCode::OptimisticConflict);
            $f->repo->saveState($ctx,$next,1);
            H::check($f->count('working_versions')===2 && $f->repo->state($ctx,$f->h->branch)?->version===2);
        } finally { $f->close(); }
    },
    'summary.source-cascade'=>static function(): void {
        $f=new Fixture();try {
            $h=$f->h;$ctx=$h->core->context;$s=$f->summary();$f->repo->append($ctx,$s,0);$source=$s->sources->messages[0];$run=new RunId($h->core->tenant,'completed:run');
            $refs=[$s->ref];$f->repo->register($ctx,$s->ref,DerivedKind::Summary,$s->sources->messages,[$run]);
            foreach ([DerivedKind::Candidate,DerivedKind::ContextCache,DerivedKind::HistoryIndex] as $kind) { $ref=$h->core->ref('derived:'.$kind->value);$refs[]=$ref;$f->repo->register($ctx,$ref,$kind,[$source],[$run]); }
            $f->repo->invalidate($ctx,$source,'source_deleted');$f->repo->invalidate($ctx,$source,'source_deleted');
            foreach ($refs as $ref) { H::check(!$f->repo->usable($ctx,$ref)); }
            H::check($f->repo->markers($ctx,$run)===[['source_id'=>$source->ref->value,'reason'=>'source_deleted']]);
            H::rejects(static fn()=> $f->repo->register($ctx,$h->core->ref('new-cache'),DerivedKind::ContextCache,[$source]),ContractViolation::class,ErrorCode::StaleContext);
            H::rejects(static fn()=> $f->repo->append($ctx,$s,0),ContractViolation::class,ErrorCode::StaleContext);
            H::rejects(static fn()=> $f->repo->usable($ctx,new Ref(new TenantId('other'),$s->ref->value)),ContractViolation::class,ErrorCode::AccessDenied);
            H::check($f->count('summary_versions')===1 && $f->count('historical_source_markers')===1);
            Proof::$observations['source_cascade']=['invalidated'=>4,'historical_markers'=>1,'future_use_denied'=>true];
        } finally { $f->close(); }
    },
    'summary.current-guards-corruption'=>static function(): void {
        $f=new Fixture();try {
            $ctx=$f->h->core->context;$s=$f->summary();$f->repo->append($ctx,$s,0);$f->authority->denied=true;
            H::rejects(static fn()=> $f->repo->head($ctx,$f->h->branch),ContractViolation::class,ErrorCode::AccessDenied);
            H::rejects(static fn()=> $f->repo->append($ctx,$s,0),ContractViolation::class,ErrorCode::AccessDenied);
            $f->authority->denied=false;$f->host->revision='changed';
            H::rejects(static fn()=> $f->repo->head($ctx,$f->h->branch),ContractViolation::class,ErrorCode::StaleContext);$f->host->revision='policy-1';
            $f->db->execute('UPDATE '.$f->db->table('summary_versions')." SET payload=replace(payload,'untrusted','policy')");
            H::rejects(static fn()=> $f->repo->head($ctx,$f->h->branch),InvalidArgumentException::class);
            $f->db->execute('UPDATE '.$f->db->table('summary_schema_versions')." SET source_hash='tampered'");
            H::rejects(static fn()=> Schema::install($f->database->pdo,$f->schema),\AgenToolsPlatform\Core\Common\IntegrityConflict::class);
        } finally { $f->close(); }
    },
]);
