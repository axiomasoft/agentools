<?php

declare(strict_types=1);

use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Routing\Fixture as F;
use AgenToolsPlatform\Core\Models\Routing\{CatalogEntry, CatalogView};

return [
    'catalog.schema-validation'=>static function (): void {
        $f = new F(); $e = $f->entry(); H::check($e->contextLimit === 8192 && $e->outputLimit === 1024);
        H::check($e->costMinor === null && $e->pricingVersion === null);
        foreach ([['extra'=>true],['region'=>''],['context_limit'=>0],['output_limit'=>9000],['quality'=>1.1],
            ['cost_minor'=>10],['endpoint'=>'https://key@provider.invalid'],['endpoint'=>'https://'],['data_classes'=>['private','private']],['capabilities'=>['invented'=>[]]]] as $change) {
            H::rejects(static fn ()=>$f->entry($change),InvalidArgumentException::class);
        }
        $data = $e->toArray(); unset($data['terms']); H::rejects(static fn ()=>CatalogEntry::fromArray($data,$f->hasher),InvalidArgumentException::class);
        H::rejects(static fn ()=>new CatalogView([$f->qualified($e),$f->qualified($e)]),InvalidArgumentException::class);
    },
    'catalog.full-hash-and-evidence'=>static function (): void {
        $f = new F(); $a = $f->entry(); $b = $f->entry(['snapshot_ref'=>'catalog-2']);
        H::check($a->hash->value !== $b->hash->value && $a->deployment->model === $b->deployment->model);
        $terms = F::object($a->toArray()['terms']); $terms['evidence'] = 'unverified';
        $f->states = [$f->qualified($f->entry(['model_evidence'=>$terms]))];
        H::check($f->router()->route($f->request(),$f->now())->selected === null);
    },
    'catalog.price-unverified-not-ranked'=>static function (): void {
        $f = new F(); $evidence = F::object($f->entry()->toArray()['terms']); $evidence['evidence'] = 'unverified';
        $e = $f->entry(['cost_minor'=>0,'pricing_version'=>'synthetic/v1','pricing_evidence'=>$evidence]);
        H::check($e->costAt($f->now()) === null);
        $evidence['evidence'] = 'documented';
        $known = $f->entry(['id'=>'z-known','cost_minor'=>5,'pricing_version'=>'synthetic/v1','pricing_evidence'=>$evidence]);
        $f->states = [$f->qualified($e),$f->qualified($known)];
        H::check($f->router()->route($f->request(),$f->now())->selected === $known);
    },
];
