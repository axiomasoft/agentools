<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php'; require_once __DIR__.'/PgFixture.php';
use AgenToolsPlatform\ModelAdapter\Tests\Cache\PgFixture;
$p=new PgFixture($argv[1]); $f=$p->f; $mode=$argv[2];
echo json_encode(['backend'=>(int)$p->sql('SELECT pg_backend_pid()')->fetchColumn()],JSON_THROW_ON_ERROR).PHP_EOL; fflush(STDOUT);
if (fgets(STDIN)!=="GO\n") { exit(2); }
if (str_starts_with($mode,'quota-')) {
    $request=$f->warm($mode); $intent=$f->accounting->intent($f->key(),$request,$f->now);
    try { $p->repository->beginWarm($intent,$f->policy(1)); echo "{\"status\":\"accepted\"}\n"; }
    catch (LogicException) { echo "{\"status\":\"refused\"}\n"; }
} elseif ($mode==='use') {
    $f->host->onAdmit=static function (): void { echo "{\"boundary\":\"admission-ready\"}\n"; fflush(STDOUT); fgets(STDIN); };
    try { $f->manager->resolve($f->prepared($f->key()),$f->now); echo "{\"status\":\"disclosed\"}\n"; }
    catch (LogicException) { echo "{\"status\":\"denied\"}\n"; }
} elseif ($mode==='revoke') {
    $p->repository->invalidate($f->key(),'owner_revoke',$f->now); echo "{\"status\":\"invalidated\"}\n";
} elseif ($mode==='after-local-revoke') {
    $p->repository->invalidate($f->key(),'owner_revoke',$f->now); echo "{\"boundary\":\"after-local-revoke\"}\n"; fflush(STDOUT); fgets(STDIN);
} elseif ($mode==='delete-ack') {
    $f->provider->afterDelete=function () use ($p): void {
        $id=$p->sql('SELECT id FROM model_cache_delete')->fetchColumn(); $p->sql("INSERT INTO host_cache_receipts VALUES(:id,'delete')",['id'=>$id]);
        echo "{\"boundary\":\"delete-ack\"}\n"; fflush(STDOUT); fgets(STDIN);
    };
    $f->manager->revoke($f->key(),$f->now);
} elseif ($mode==='warm-ack') {
    $f->provider->afterCreate=function () use ($p): void {
        $p->sql("INSERT INTO host_cache_receipts VALUES('warm-1','create')"); echo "{\"boundary\":\"warm-ack\"}\n"; fflush(STDOUT); fgets(STDIN);
    };
    $f->manager->warm($f->key(),$f->warm(),$f->policy(),$f->now);
} else { exit(3); }
