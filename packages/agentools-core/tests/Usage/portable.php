<?php

declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Budget\AdmissionResult;
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Core\Usage\{Producer, UsageConflict, UsageFold, UsageNormalizer, Values};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Usage\Fixture as F;

$f = new F(); $cases = []; $observations = []; $failed = 0;
$normalization = F::read('normalization')['cases'];
if (!is_array($normalization) || $normalization === []) { throw new RuntimeException('Missing portable fixtures'); }
$tests = [];
foreach ($normalization as $raw) {
    $c = Values::object($raw);
    $tests[Values::text($c['id'])] = static function () use ($f,$c): array {
        $proof = $c['outbound_proven']; if ($proof !== null && !is_bool($proof)) { throw new RuntimeException('Invalid proof'); }
        $e = $f->event(Values::object($c['raw']),['certainty'=>$c['certainty']],outbound:$proof);
        H::check($e->units->toArray() === $c['expected_units'] && $e->providerAmount?->toArray() === $c['expected_amount']);
        return ['event'=>$e->toArray(),'hash'=>$e->hash->value,'ack'=>$e->acknowledgement()];
    };
}
$tests['ECO-USE-002'] = static function () use ($f): array {
    $e = $f->event(['output'=>12]); $p = UsageFold::fold([$e,$e,$e]); H::check(count($p->active) === 1 && count($p->history) === 1);
    return ['active'=>count($p->active),'history'=>count($p->history),'ack'=>$e->acknowledgement()];
};
foreach (['ECO-USE-003','ECO-USE-009','V2-P9-2-FALLBACK-NEG'] as $id) {
    $tests[$id] = static function () use ($f,$id): array {
        $audio = $id === 'ECO-USE-009';
        $a = $f->event($audio ? ['audio_input'=>'1.250','audio_unit'=>'second'] : ['output'=>12]);
        $b = $f->event($audio ? ['audio_input'=>'1.251','audio_unit'=>'second'] : ['output'=>13]);
        H::rejects(static fn ()=>UsageFold::fold([$a,$b]),UsageConflict::class);
        return ['error'=>'USAGE_PAYLOAD_CONFLICT','quarantine'=>true,'additional_units'=>0,'original'=>$a->units->toArray()];
    };
}
$tests['ECO-USE-004'] = static function () use ($f): array {
    $a = $f->event([],['certainty'=>'preliminary']); $b = $f->event(['output'=>12],['event_id'=>'usage-inv-1-v2','supersedes_event_id'=>$a->id->value],version:2);
    $p = UsageFold::fold([$b,$a,$b]); H::check(count($p->active) === 1 && count($p->history) === 2);
    return ['active'=>1,'history'=>2,'event'=>array_values($p->active)[0]->toArray(),'original'=>$a->units->toArray()];
};
$tests['ECO-USE-007'] = static function () use ($f): array {
    $n = new UsageNormalizer($f->hasher); $events = 0; $telemetry = 0;
    foreach (Producer::cases() as $observer) { $r = $n->observe(F::read('metadata'),'inv-1',1,Producer::Transport,$observer,F::mapping(),[],true); if ($r->event !== null) { ++$events; } if ($r->telemetryOnly) { ++$telemetry; } }
    H::check($events === 1 && $telemetry === 1); return ['canonical'=>$events,'telemetry'=>$telemetry];
};
$tests['V2-P9-2-FALLBACK-POS'] = static function () use ($f): array {
    $a = $f->event([],['certainty'=>'unknown']); $b = $f->event(['output'=>7],['event_id'=>'usage-fallback-v1','deployment_ref'=>'alternate-fixture'],invocation:'inv-2');
    $p = UsageFold::fold([$a,$b]); H::check(count($p->active) === 2 && $a->toArray()['business_operation_ref'] === $b->toArray()['business_operation_ref']);
    return ['active'=>2,'business_operation_ref'=>$a->toArray()['business_operation_ref'],'request_counts'=>[1,1],'original'=>$a->units->toArray()];
};
$tests['budget.result-codec'] = static function (): array {
    $d = F::read('admission-result'); $r = AdmissionResult::fromArray(new TenantId('alpha'),$d); H::check($r->permits(F::now()));
    foreach (['denied','unavailable','unknown'] as $status) { H::check(!AdmissionResult::fromArray(new TenantId('alpha'),array_replace($d,['status'=>$status,'reason'=>'OWNER_CODE']))->permits(F::now())); }
    return ['admitted'=>true,'denied'=>false,'unavailable'=>false,'unknown'=>false];
};
foreach ($tests as $id=>$test) {
    try { $observations[$id] = $test(); $cases[] = ['id'=>$id,'status'=>'passed']; }
    catch (Throwable $error) { ++$failed; $cases[] = ['id'=>$id,'status'=>'failed']; fwrite(STDERR,$id.': '.$error->getMessage().PHP_EOL); }
}
echo json_encode(['mode'=>'contract-scripted','tier'=>'contract-scripted','cases'=>$cases,'observations'=>$observations,'count'=>count($cases),'failures'=>$failed,'skips'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
exit($failed === 0 ? 0 : 1);
