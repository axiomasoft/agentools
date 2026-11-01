<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Conformance\WriteJourney\{Journey, Owner};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

$j=new Journey(new IsolatedDatabase(),$argv[1]);
$j->database->pdo->prepare("SELECT set_config('application_name',:name,false)")->execute(['name'=>'write_'.$argv[1].'_'.$argv[2]]);
$target=$argv[2];
echo "ready\n"; fflush(STDOUT);
if (trim((string)fgets(STDIN))!=='go') { exit(2); }
if (str_starts_with($target,'source-')) { $j->accept(); echo "accepted\n"; exit(0); }
if ($target==='zombie') {
    $grant=$j->confirm(); $lease=$j->claim(1000); $ledger=$j->ledger($grant);
    $request=$ledger->prepare('synthetic.documents',\AgenToolsPlatform\Core\Actions\Effects\Strategy::NativeKey);
    echo "prepared\n"; fflush(STDOUT); if (trim((string)fgets(STDIN))!=='resume') { exit(3); }
    $staleRun=false;
    $plan=$j->plan();
    $admission=new \AgenToolsPlatform\Core\Budget\AdmissionRequest($j->ref('admit:'.$plan->request->invocationId->value),[
        'tenant_ref'=>$j->tenant->value,'actor_ref'=>$j->context->identity->actorRef->toArray(),
        'agent_principal_ref'=>$j->context->identity->agentPrincipalRef?->toArray(),'plan'=>$plan->identity(),
        'context_scope'=>$j->context->scopeHash($j->hasher)->value,'subject'=>$j->context->identity->subjectRef?->toArray(),'purpose'=>$j->context->purpose],$j->hasher);
    try { (new \AgenToolsPlatform\Postgres\Models\InvocationRepository($j->environment))->open($j->context,$lease,$plan,$admission); }
    catch (ContractViolation $e) { $staleRun=$e->errorCode===ErrorCode::StaleClaim; }
    $outcome=(new Owner($j,$ledger->epoch))->execute($request); // Owner key must return its original receipt.
    $staleEffect=false;
    try { $ledger->accept($outcome); } catch (ContractViolation $e) { $staleEffect=$e->errorCode===ErrorCode::StaleClaim; }
    echo json_encode(['stale_run'=>$staleRun,'stale_effect'=>$staleEffect,'owner_writes'=>$j->count('example_owner_writes')],JSON_THROW_ON_ERROR)."\n";
    exit($staleRun&&$staleEffect?0:4);
}
$j->barrier=static function (string $name) use ($target): void {
    if ($name===$target) {
        echo 'boundary:'.$name."\n"; fflush(STDOUT);
        if (trim((string)fgets(STDIN))==='continue') { return; }
        throw new RuntimeException('Parent did not terminate the worker at the boundary');
    }
};
$result=$j->drive(); if ($result==='completed') { $j->deliver(); }
echo 'result:'.$result."\n";
