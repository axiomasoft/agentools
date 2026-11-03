<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Postgres\Tests\Usage\{Fixture,Meter};
use AgenToolsPlatform\Postgres\Usage\Dispatcher;
use AgenToolsPlatform\Tests\Usage\Fixture as Values;

$f=new Fixture($argv[1]); $mode=$argv[2];
echo json_encode(['backend'=>(int)$f->rig->sql('SELECT pg_backend_pid()')->fetchColumn()],JSON_THROW_ON_ERROR).PHP_EOL; fflush(STDOUT);
if (fgets(STDIN)!=="GO\n") { exit(2); }
$event=(new Values())->event([],['tenant_ref'=>'tenant-a','event_id'=>'competing-event'],'competing-inv');
if ($mode==='append') { echo json_encode(['ack'=>$f->usage->append($event)],JSON_THROW_ON_ERROR).PHP_EOL; }
elseif ($mode==='before-commit') {
    $f->usage->db->transaction(function () use ($f,$event): void { $f->usage->appendInTransaction($event); echo "{\"boundary\":\"before-commit\"}\n"; fflush(STDOUT); fgets(STDIN); });
} elseif ($mode==='after-commit') {
    $f->usage->append($event); echo "{\"boundary\":\"after-commit\"}\n"; fflush(STDOUT); fgets(STDIN);
} elseif ($mode==='export-ack') {
    $meter=new Meter($f); $meter->afterCommit=static function (): void { echo "{\"boundary\":\"export-ack\"}\n"; fflush(STDOUT); fgets(STDIN); };
    (new Dispatcher($f->usage,$meter))->dispatchOne();
} else { exit(3); }
