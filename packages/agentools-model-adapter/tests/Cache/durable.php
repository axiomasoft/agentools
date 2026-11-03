<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php'; require_once __DIR__.'/PgFixture.php';
use AgenToolsPlatform\Core\Common\{ContractViolation,IntegrityConflict};
use AgenToolsPlatform\Core\Models\Cache\ProviderResult;
use AgenToolsPlatform\ModelAdapter\Tests\Cache\{PgFixture,Proof};
use AgenToolsPlatform\Tests\Harness as H;

Proof::run([
 'cache.pg-scope-revoke'=>static function (): void {
    $p=new PgFixture(); try {
        $f=$p->f; $p->repository->put($f->descriptor()); H::check($p->repository->get($f->key())->usable($f->now));
        H::rejects(fn ()=>$p->repository->get($f->key(['tenant_ref'=>'beta'])),ContractViolation::class);
        $f->provider->enabled=[]; H::check($f->manager->revoke($f->key(),$f->now)->status==='unsupported');
        $p->repository->put($f->descriptor()); H::check(!$p->repository->get($f->key())->usable($f->now));
        H::check($p->sql("SELECT status FROM model_cache_delete")->fetchColumn()==='unsupported');
        \AgenToolsPlatform\Postgres\Cache\Schema::install($p->database->pdo,$p->schema);
        $p->sql("UPDATE model_cache_schema SET source_hash='mismatch'");
        H::rejects(fn ()=>\AgenToolsPlatform\Postgres\Cache\Schema::install($p->database->pdo,$p->schema),IntegrityConflict::class);
    } finally { $p->drop(); }
 },
 'cache.pg-warm-accounting'=>static function (): void {
    $p=new PgFixture(); try {
        $f=$p->f; $request=$f->warm(); H::check($f->manager->warm($f->key(),$request,$f->policy(),$f->now)!==null);
        H::check($p->sql('SELECT count(*) FROM accounting_usage')->fetchColumn()===1 && $p->sql('SELECT count(*) FROM model_cache_warm')->fetchColumn()===1);
        H::rejects(fn ()=>$p->repository->db->execute('UPDATE '.$p->repository->db->table('model_cache_entry').' SET tenant_id=\'beta\''),ContractViolation::class);
        $bad=new \AgenToolsPlatform\ModelAdapter\Cache\WarmRequest($request->operation,$request->admission,$request->usageMetadata,$request->expiresAt,11,5);
        H::rejects(fn ()=>$f->manager->warm($f->key(),$bad,$f->policy(),$f->now),IntegrityConflict::class);
        H::check($f->provider->creates===1);
        $row=$p->sql('SELECT intent,reservation_ref FROM model_cache_warm')->fetch(\PDO::FETCH_ASSOC); $intent=json_decode($row['intent'],true,flags:JSON_THROW_ON_ERROR);
        H::check(json_decode($intent['admission_json'],true,flags:JSON_THROW_ON_ERROR)['operation_key']===$request->admission->operationKey->value && $row['reservation_ref']!==null);
    } finally { $p->drop(); }
 },
 'cache.pg-warm-rollback'=>static function (): void {
    $p=new PgFixture(); try {
        $f=$p->f;
        $p->sql("CREATE FUNCTION fail_cache() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'cache rollback'; END \$\$");
        $p->sql('CREATE CONSTRAINT TRIGGER fail_cache AFTER INSERT ON model_cache_entry DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fail_cache()');
        H::rejects(fn ()=>$f->manager->warm($f->key(),$f->warm(),$f->policy(),$f->now),\Throwable::class);
        H::check($p->sql('SELECT count(*) FROM model_cache_entry')->fetchColumn()===0 && $p->sql('SELECT count(*) FROM accounting_usage')->fetchColumn()===0);
        H::check($p->sql('SELECT status FROM model_cache_warm')->fetchColumn()==='sent');
        $p->sql('DROP TRIGGER fail_cache ON model_cache_entry'); $p->sql("UPDATE model_cache_warm SET updated_at=clock_timestamp()-interval '1 minute'");
        $fresh=new PgFixture($p->schema); H::check($fresh->f->manager->reconcileWarm());
        H::check($fresh->f->provider->creates===0 && $p->sql("SELECT usage->>'certainty' FROM model_cache_warm")->fetchColumn()==='unknown');
        H::check($p->sql('SELECT count(*) FROM accounting_usage')->fetchColumn()===1);
    } finally { $p->drop(); }
 },
 'cache.pg-revoked-inflight'=>static function (): void {
    $p=new PgFixture(); try {
        $f=$p->f; $f->provider->afterCreate=fn ()=>$f->manager->revoke($f->key(),$f->now);
        H::check($f->manager->warm($f->key(),$f->warm(),$f->policy(),$f->now)===null);
        H::check(!$p->repository->get($f->key())->usable($f->now) && $p->sql('SELECT count(*) FROM model_cache_delete')->fetchColumn()===1);
        H::check($p->sql('SELECT count(*) FROM accounting_usage')->fetchColumn()===1);
        H::check($f->manager->reconcileDeletion($f->now) && $p->sql('SELECT status FROM model_cache_delete')->fetchColumn()==='deleted');
    } finally { $p->drop(); }
    $p=new PgFixture(); try {
        $f=$p->f; $p->repository->put($f->descriptor());
        $f->provider->onRefresh=fn ()=>$p->repository->invalidate($f->key(),'idle_retire',$f->now);
        H::check($f->manager->warm($f->key(),$f->warm('refresh-race','refresh'),$f->policy(),$f->now)===null);
        H::check(!$p->repository->get($f->key())->usable($f->now) && $p->sql('SELECT count(*) FROM model_cache_fence')->fetchColumn()===0);
        $f->provider->onRefresh=null;
        H::check($f->manager->warm($f->key(),$f->warm('demand-after-retire'),$f->policy(),$f->now)!==null);
    } finally { $p->drop(); }
 },
 'cache.pg-deletion-replay'=>static function (): void {
    $p=new PgFixture(); try {
        $f=$p->f; $p->repository->put($f->descriptor()); $f->provider->lostDelete=true;
        $f->provider->afterDelete=function () use ($p): void {
            $id=$p->sql('SELECT id FROM model_cache_delete')->fetchColumn(); $p->sql("INSERT INTO host_cache_receipts VALUES(:id,'delete')",['id'=>$id]);
        };
        $d=$f->manager->revoke($f->key(),$f->now); H::check($d->status==='unknown'); $p->sql('UPDATE model_cache_delete SET next_at=clock_timestamp()');
        $fresh=new PgFixture($p->schema); $fresh->f->provider->onStatus=function ($operation) use ($fresh): ProviderResult {
            H::check($fresh->sql('SELECT kind FROM host_cache_receipts WHERE id=:id',['id'=>$operation->value])->fetchColumn()==='delete');
            return new ProviderResult('deleted',receipt:$fresh->f->ref('owner-delete-proof'));
        };
        H::check($fresh->f->manager->reconcileDeletion($fresh->f->now) && $fresh->f->provider->deletes===0 && $fresh->f->provider->lookups===[$d->id->value]);
        H::check($p->sql('SELECT status FROM model_cache_delete')->fetchColumn()==='deleted' && !$fresh->repository->get($fresh->f->key())->usable($fresh->f->now));
    } finally { $p->drop(); }
 },
],true);
