<?php

declare(strict_types=1);

require_once __DIR__.'/Fixture.php';

use AgenToolsPlatform\Tests\Confirmation\Fixture;

$fixture=new Fixture($argv[1]);
$c=$fixture->composition;
$fixture->database->pdo->prepare("SELECT set_config('application_name',:name,false)")->execute(['name'=>'confirmation_'.$argv[1].'_'.$argv[2]]);
$fixture->replayPdo->prepare("SELECT set_config('application_name',:name,false)")->execute(['name'=>'confirmation_'.$argv[1].'_'.$argv[2].'_replay']);
$submission=$c->submission();
$recover=str_starts_with($argv[2],'recover-');
if (!$recover) { $preview=$c->confirmation->prepare($submission); }
echo "ready\n"; fflush(STDOUT);
if (trim((string)fgets(STDIN))!=='go') { exit(2); }
try {
    if ($recover) {
        $db=$c->environment->db;
        $row=$db->row('SELECT p.action_ref,p.id,p.binding_hash,p.binding FROM '.$db->table('confirmation_previews').' p');
        if ($row===null) { throw new \RuntimeException('Missing recovery proof'); }
        $tuple=json_decode(\AgenToolsPlatform\Postgres\Runtime\Connection::text($row,'binding'),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($tuple) || !isset($tuple['action_version'],$tuple['epoch']) || !is_int($tuple['action_version']) || !is_int($tuple['epoch'])) {
            throw new \RuntimeException('Invalid recovery proof');
        }
        $grant=new \AgenToolsPlatform\Core\Actions\Confirmation\ConfirmedAction(
            new \AgenToolsPlatform\Core\Common\ActionId($c->host->tenant,\AgenToolsPlatform\Postgres\Runtime\Connection::text($row,'action_ref')),
            new \AgenToolsPlatform\Core\Common\Ref($c->host->tenant,\AgenToolsPlatform\Postgres\Runtime\Connection::text($row,'id')),
            new \AgenToolsPlatform\Core\Common\PayloadHash(\AgenToolsPlatform\Postgres\Runtime\Connection::text($row,'binding_hash')),$tuple['action_version']+2,$tuple['epoch']);
        $fixture->owner->reconcile($grant,$submission);
        echo "recovered\n"; exit(0);
    }
    $receipt=$c->confirmation->commit($preview->token,$submission);
    if ($argv[2]==='after-confirmation') { echo "committed\n"; fflush(STDOUT); fgets(STDIN); exit(0); }
    $fixture->owner->write($receipt,$submission);
    echo "owner-committed\n";
} catch (\AgenTools\Confirmation\ConfirmationFailure $error) {
    fwrite(STDERR,$error->error->code."\n"); exit(3);
}
