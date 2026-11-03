<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Loqum\Tests\Delivery\Fixture;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

$database=new IsolatedDatabase();$f=new Fixture($database->pdo,$argv[1]);$s=$f->segment();
$f->delivery->register($s);$f->ledger->claim($s,1);
fwrite(STDOUT,"intent-saved\n");fflush(STDOUT);
posix_kill(getmypid(),SIGKILL);
throw new RuntimeException('Required crash did not terminate the worker');
