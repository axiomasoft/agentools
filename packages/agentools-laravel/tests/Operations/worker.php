<?php

declare(strict_types=1);

require_once __DIR__.'/Fixture.php';

$f=new \AgenToolsPlatform\Laravel\Tests\Operations\Fixture($argv[1]);
$f->f->database->pdo->prepare("SELECT set_config('application_name',:name,false)")->execute(['name'=>'operator_'.$argv[1].'_'.$argv[2]]);
echo "ready\n"; fflush(STDOUT);
if (trim((string)fgets(STDIN))!=='go') { exit(2); }
$result=$f->operations->cancelRun($f->op('run_cancel',$f->run,0,0));
echo "committed\n"; fflush(STDOUT);
if ($argv[2]==='crash') { fgets(STDIN); }
echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
