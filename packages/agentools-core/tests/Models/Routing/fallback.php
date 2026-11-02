<?php

declare(strict_types=1);

use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Routing\Fixture as F;
use AgenToolsPlatform\Core\Models\Routing\{FallbackRouter, FallbackState, QualifiedTransport};

$cases = [];
foreach (F::fallbackMatrix() as $case) {
    $cases[$case['id']] = static function () use ($case): void {
        $f = new F(); $base = $f->modelRequest(); $a = $f->entry(); $b = $f->entry(['id'=>'ru-alternate','region'=>$case['forbidden_only'] ? 'EU' : 'RU']);
        $f->states = [$f->qualified($a),$f->qualified($b)];
        $state = new FallbackState($base->invocationId,F::ref('bundle'),F::ref('goal'),[F::ref('action-1')],$a->deployment->ref->value,F::ref('outage-1'),
            $case['disclosed'],$case['unknown_effect'] ? F::ref('owner-operation') : null,$case['disclosed'] ? F::ref('delivered-1') : null);
        $router = new FallbackRouter($f->router(),$f->hasher);
        $result = $router->plan($f->request(),$state,$f->now(),$case['explicit'],$case['allowed']);
        H::check($result->outcome === $case['outcome'] && !$result->retryActions && $result->origin === $state && $f->calls === 0);
        H::check($result->origin->actions[0]->value === 'action-1');
        if ($result->invocation !== null) {
            H::check($result->invocation->value !== $base->invocationId->value && $result->route?->selected === $b);
            H::check($router->plan($f->request(),$state,$f->now(),$case['explicit'],$case['allowed'])->invocation?->value === $result->invocation->value);
            H::check($result->origin->bundle->value === 'bundle' && $result->origin->goal->value === 'goal');
            if ($case['disclosed']) { H::check($result->segment !== null && $result->origin->disclosedSegment?->value === 'delivered-1'); }
        } else { H::check($result->segment === null); }
    };
}
$cases['V2-P9-1-RECONCILE-POS'] = static function (): void {
    $f = new F(); $base = $f->modelRequest(); $e = $f->entry(['id'=>'ru-alternate']); $f->states = [$f->qualified($e)];
    $router = new FallbackRouter($f->router(),$f->hasher);
    $unknown = new FallbackState($base->invocationId,F::ref('bundle'),F::ref('goal'),[F::ref('action-1')],'ru-primary',F::ref('outage-1'),false,F::ref('owner-key-1'));
    H::check($router->plan($f->request(),$unknown,$f->now())->outcome === 'reconcile_required');
    // Trusted host looks up the same owner key; the routing API never submits the Action.
    $known = $f->lookupOwner($unknown->unknownEffect ?? throw new RuntimeException('Missing owner key')); H::check($known['outcome'] === 'success' && $known['action'] === $unknown->actions[0]->value);
    $state = new FallbackState($unknown->invocation,$unknown->bundle,$unknown->goal,$unknown->actions,$unknown->deployment,F::ref('reconciled-outage-1'),false);
    $result = $router->plan($f->request(),$state,$f->now());
    H::check($result->outcome === 'reroute' && !$result->retryActions && $result->origin->actions === $unknown->actions && $f->ownerOperationCount() === 1 && $f->ownerLookups === 1);
};
$cases['V2ALL-MODEL-FAULT'] = static function (): void {
    $f = new F(); $a = $f->entry(); $b = $f->entry(['id'=>'ru-alternate']); $f->states = [$f->qualified($a),$f->qualified($b)];
    $transport = new QualifiedTransport($f,$f->router(),$f->request(),$a,$f); $f->outage = true;
    H::rejects(static fn ()=>$transport->generate($f->modelRequest(),$a->deployment),RuntimeException::class);
    H::check($f->calls === 1);
    $state = new FallbackState($f->modelRequest()->invocationId,F::ref('bundle'),F::ref('goal'),[],'ru-primary',F::ref('outage'),false,attemptedDeployments:['ru-alternate']);
    $result = (new FallbackRouter($f->router(),$f->hasher))->plan($f->request(),$state,$f->now());
    H::check($result->outcome === 'non_ai_text' && $result->invocation === null && $f->calls === 1);
};
return $cases;
