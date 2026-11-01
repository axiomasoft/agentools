<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Postgres\Tests\Summaries\Fixture;

$schema=$argv[1]??throw new RuntimeException('Schema required');$mode=$argv[2]??'append';$text=$argv[3]??'baseline';
$f=new Fixture($schema);$row=$f->db->row('SELECT pg_backend_pid() AS pid');
if ($row===null) { throw new RuntimeException('Backend missing'); }
echo json_encode(['ready'=>true,'backend'=>\AgenToolsPlatform\Postgres\Runtime\Connection::number($row,'pid')],JSON_THROW_ON_ERROR).PHP_EOL;flush();
if (trim((string)fgets(STDIN))!=='GO') { throw new RuntimeException('Start barrier missing'); }
try {
    $summary=$f->summary(text:$text);$f->repo->append($f->h->core->context,$summary,0);
    echo json_encode(['outcome'=>'appended','hash'=>$summary->hash->value],JSON_THROW_ON_ERROR).PHP_EOL;flush();
    if ($mode==='hold') { sleep(30); }
} catch (ContractViolation $error) { echo json_encode(['outcome'=>$error->errorCode->value],JSON_THROW_ON_ERROR).PHP_EOL; }
