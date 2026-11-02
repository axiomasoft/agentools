<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Usage\{Amount, Producer, ProviderMapping, Units, UsageEvent, UsageNormalizer, Values};
use AgenToolsPlatform\Tests\{Harness as H};
use AgenToolsPlatform\Tests\Usage\Fixture as F;

$tests = [];
$cases = F::read('normalization')['cases'];
if (!is_array($cases) || $cases === []) { throw new RuntimeException('Missing normalization cases'); }
foreach ($cases as $rawCase) {
    $c = Values::object($rawCase); $id = Values::text($c['id']);
    $tests[$id] = static function () use ($c): void {
        $f = new F(); $outbound = $c['outbound_proven'];
        if ($outbound !== null && !is_bool($outbound)) { throw new RuntimeException('Invalid outbound proof'); }
        $e = $f->event(Values::object($c['raw']),['certainty'=>Values::text($c['certainty'])],outbound:$outbound);
        H::check($e->units->toArray() === $c['expected_units']); H::check($e->providerAmount?->toArray() === $c['expected_amount']);
        H::check(!array_key_exists('raw',$e->toArray()));
    };
}
$tests['usage.invalid-quantities-and-overlap'] = static function (): void {
    $f = new F();
    foreach ([['input_total'=>100,'cached_subset'=>101],['input_total'=>100,'cached_subset'=>50,'cache_write'=>51],
        ['output'=>1,'reasoning'=>2],['output'=>-1],['output'=>1.2],['output'=>'1'],['audio_input'=>1.25,'audio_unit'=>'second'],
        ['audio_input'=>'1.250'],['audio_input'=>"1\n",'audio_unit'=>'second'],['amount'=>['amount'=>0.0021,'currency'=>'XXX']]] as $raw) {
        H::rejects(static fn ()=>$f->event($raw),InvalidArgumentException::class);
    }
    $e = $f->event(); $d = $e->toArray(); $d['units'] = Values::object($d['units']); unset($d['units']['reasoning_tokens']);
    H::rejects(static fn ()=>UsageEvent::fromArray($d,$f->hasher),InvalidArgumentException::class);
    H::rejects(static fn ()=>new Amount('0.1',''),InvalidArgumentException::class);
    H::rejects(static fn ()=>new ProviderMapping('bad',[],true,'unknown','subset_of_output'),InvalidArgumentException::class);
};
$tests['usage.separate-adapter-mapping'] = static function (): void {
    $f = new F(); $m = new ProviderMapping('separate/v1',['input'=>'uncached','cache_read'=>'cached','cache_write'=>'written','output'=>'output','reasoning'=>'extra'],false,'separate','separate');
    $e = (new UsageNormalizer($f->hasher))->observe(F::read('metadata'),'inv-1',1,Producer::Transport,Producer::Transport,$m,
        ['uncached'=>60,'cached'=>40,'written'=>100,'output'=>12,'extra'=>20,'secret'=>'never-export'],true)->event ?? throw new RuntimeException('Missing canonical event');
    H::check($e->units->toArray()['input_uncached_tokens'] === 60 && $e->units->toArray()['reasoning_tokens'] === 20);
    H::check(!str_contains(json_encode($e->toArray(),JSON_THROW_ON_ERROR),'never-export'));
};
return $tests;
