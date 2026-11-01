<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Actions\Confirmation\Decision;
use AgenToolsPlatform\Core\Common\{Ref, TenantId};
use AgenToolsPlatform\Examples\ConfirmedWrite\{Composition, Host, KeyMaterial, Owner};

if (count($argv)!==3 || $argv[2]!=='synthetic-ui') {
    fwrite(STDERR,"Usage: php run.php <provisioned-example-schema> synthetic-ui\n"); exit(2);
}
$connect=static fn (): PDO=>new PDO((string)getenv('AGENTOOLS_TEST_PG_DSN'),(string)getenv('AGENTOOLS_TEST_PG_USER'),
    (string)getenv('AGENTOOLS_TEST_PG_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$keys=new KeyMaterial((string)base64_decode((string)getenv('AGENTOOLS_CONFIRMATION_SIGNING_KEY'),true),
    (string)base64_decode((string)getenv('AGENTOOLS_CONFIRMATION_SEAL_KEY'),true));
$host=new Host(new TenantId('tenant-a'));
$c=new Composition($connect(),$connect(),$argv[1],$host,$keys);
$submission=$c->submission();
$preview=$c->confirmation->prepare($submission);
// Explicitly simulated host UI boundary. Real deployments inject authenticated UI/voice event readers.
$host->consent=new Decision(new Ref($host->tenant,'synthetic-ui:'.$preview->proposal->value),
    $c->hasher->hash($host->tenant,'synthetic-ui-decision',$preview->binding->hash->value),new Ref($host->tenant,$host->actor),
    $preview->binding->hash,'ui',$preview->binding->expiresAt);
$c->confirmation->approve($preview->token,$submission);
$receipt=$c->confirmation->commit($preview->token,$submission);
(new Owner($c,$connect(),$argv[1]))->write($receipt,$submission);
$status=$c->actions->status($receipt->action,$c->context());
echo json_encode(['action_ref'=>$receipt->action->toArray(),'status'=>$status->receipt->wireStatus,'outcome'=>$status->outcome()?->value,
    'tier'=>'isolated-postgres','limitations'=>'Synthetic host/owner/UI; no provider, production integration or qualification.'],JSON_THROW_ON_ERROR)."\n";
