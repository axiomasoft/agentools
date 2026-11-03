<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Tests\Qualification\Fixture;
use AgenToolsPlatform\Tests\Routing\Fixture as F;
$database = new IsolatedDatabase(); $db = new Connection($database->pdo,$argv[1]); $f = new Fixture($db);
$db->execute('INSERT INTO '.$db->table('qualification_worker_ready').' VALUES(:id)',['id'=>$argv[2]]);
$db->execute('SELECT pg_advisory_lock(812220)'); $db->execute('SELECT pg_advisory_unlock(812220)');
$candidate = $f->q->snapshot('new',$f->q->f->entry(['id'=>'canary']));
$entry = $f->q->f->entry(['id'=>'canary']);
$f->q->f->states = [$f->q->f->qualified($f->q->f->entry()),$f->q->f->qualified($entry)];
$pin = $f->repo->pin(F::ref('scope'),F::ref($argv[2]),$f->q->measurements($candidate),$f->q->f->now(),$f);
echo json_encode($pin->toArray(),JSON_THROW_ON_ERROR).PHP_EOL;
