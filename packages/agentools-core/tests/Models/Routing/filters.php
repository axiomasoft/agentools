<?php

declare(strict_types=1);

use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Routing\Fixture as F;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Models\{Capability, Support};
use AgenToolsPlatform\Core\Models\Routing\{AdminState, DeploymentState, EmergencyRule, Health, HostDecision, Qualification, QualificationTuple, Reason};

return [
    'CM-023'=>static function (): void {
        $f = new F(); $r = $f->request(['capabilities'=>[Capability::Text,Capability::Tools]]); $data = $f->entry()->toArray();
        foreach ([['support'=>'unknown'],['support'=>'unsupported'],['evidence'=>'unverified'],['expires_at'=>$f->now()->value],['checked_at'=>'2026-11-03T00:00:00Z']] as $change) {
            $caps = F::object($data['capabilities']); $caps['tools'] = array_replace(F::object($caps['tools']),$change);
            $f->states = [$f->qualified($f->entry(['capabilities'=>$caps]),$r)];
            $d = $f->router()->route($r,$f->now()); H::check(in_array(Reason::Capability,$d->rejected['ru-primary'],true) && $f->calls === 0);
        }
    },
    'V2ALL-MODEL-POSITIVE'=>static function (): void {
        $f = new F(); $a = $f->entry(); $b = $f->entry(['id'=>'ru-alternate','quality'=>100]);
        $foreign = $f->entry(['id'=>'cheap-forbidden','region'=>'EU','quality'=>999999]);
        $f->states = [$f->qualified($foreign),$f->qualified($b),$f->qualified($a)];
        $r = $f->request(['knownRoute'=>'teacher/math/screen']);
        $router = $f->router(['teacher/math/screen'=>'ru-primary']); $one = $router->route($r,$f->now());
        $f->states = array_reverse($f->states); $two = $router->route($r,$f->now());
        H::check($one->selected === $a && $two->selected === $a && $one->rejected === $two->rejected);
        H::check($one->rejected['cheap-forbidden'] === [Reason::Region] && $one->rejected['ru-alternate'] === [Reason::Rank]);
        $expected = F::read('decision.json');
        H::check($one->outcome() === $expected['outcome'] && $one->selected?->deployment->ref->value === $expected['selected']);
        H::check(array_map(static fn (array $reasons): array => array_map(static fn (Reason $reason): string => $reason->value,$reasons),$one->rejected) === $expected['rejected']);
    },
    'routing.qualification-exact-tuple'=>static function (): void {
        $f = new F(); $e = $f->entry(); $r = $f->request(); $f->states = [$f->qualified($e,$r)];
        foreach ([['promptHash'=>F::digest('b')],['skillsHash'=>F::digest('b')],['toolsHash'=>F::digest('b')],
            ['corpusHash'=>F::digest('b')],['bundle'=>F::ref('bundle-2')],['task'=>'changed'],['purpose'=>'changed'],
            ['suiteVersions'=>['technical/v2'=>F::digest('b')->value]]] as $change) {
            H::check($f->router()->route($f->request($change),$f->now())->selected === null);
        }
        foreach (['model_revision'=>'synthetic-revision-2','snapshot_ref'=>'new-snapshot','account'=>'other-account','region'=>'RU-2'] as $field=>$value) {
            $changed = $f->entry([$field=>$value]); $f->states = [new DeploymentState($changed,AdminState::Enabled,Health::Healthy,2,$f->qualified($e)->qualifications)];
            H::check(in_array(Reason::Qualification,$f->router()->route($r,$f->now())->rejected['ru-primary'],true));
        }
    },
    'routing.qualification-expiry-and-rejection'=>static function (): void {
        $f = new F(); $e = $f->entry(); $q = $f->qualified($e)->qualifications[0];
        foreach (['rejected','insufficient_evidence','qualified'] as $decision) {
            $cert = new Qualification($q->ref,$q->tupleHash,$decision,$q->issuedAt,$decision === 'qualified' ? $f->now() : $q->expiresAt,$q->evidence);
            $f->states = [$f->qualified($e,null,['qualifications'=>[$cert]])];
            H::check($f->router()->route($f->request(),$f->now())->selected === null);
        }
    },
    'routing.qualification-current-rejection'=>static function (): void {
        $f = new F(); $e = $f->entry(); $q = $f->qualified($e)->qualifications[0];
        $new = new Qualification(F::ref('new-q'),$q->tupleHash,'rejected',$f->now(),null,F::ref('new-results'));
        foreach ([[$q,$new],[$new,$q]] as $certs) {
            $f->states = [$f->qualified($e,null,['qualifications'=>$certs])];
            H::check($f->router()->route($f->request(),$f->now())->selected === null);
        }
    },
    'routing.admin-separate-health'=>static function (): void {
        $f = new F(); $e = $f->entry();
        foreach (AdminState::cases() as $admin) {
            $f->states = [$f->qualified($e,null,['admin'=>$admin])];
            H::check(($f->router()->route($f->request(),$f->now())->selected !== null) === ($admin === AdminState::Enabled));
            H::check(($f->router()->revalidate($f->request(),$e,$f->now(),true) === []) === in_array($admin,[AdminState::Enabled,AdminState::Draining],true));
        }
        foreach (Health::cases() as $health) {
            $f->states = [$f->qualified($e,null,['health'=>$health])];
            H::check(($f->router()->route($f->request(),$f->now())->selected !== null) === in_array($health,[Health::Healthy,Health::Degraded],true));
        }
    },
    'routing.legal-data-host-quota'=>static function (): void {
        $f = new F(); $e = $f->entry(); $f->states = [$f->qualified($e)];
        foreach (['legal'=>Reason::Legal,'permission'=>Reason::Host,'quota'=>Reason::Quota] as $field=>$reason) {
            foreach ([Support::Unknown,Support::Unsupported] as $support) {
                $f->host = new HostDecision(...array_replace(['legal'=>Support::Supported,'permission'=>Support::Supported,'quota'=>Support::Supported],[$field=>$support]));
                H::check(in_array($reason,$f->router()->route($f->request(),$f->now())->rejected['ru-primary'],true));
            }
        }
        $f->host = new HostDecision(Support::Supported,Support::Supported,Support::Supported);
        H::check(in_array(Reason::Data,$f->router()->route($f->request(['dataClasses'=>['restricted']]),$f->now())->rejected['ru-primary'],true));
        $terms = F::object($e->toArray()['terms']); $terms['expires_at'] = $f->now()->value;
        $f->states = [$f->qualified($f->entry(['terms'=>$terms]))];
        H::check(in_array(Reason::Legal,$f->router()->route($f->request(),$f->now())->rejected['ru-primary'],true));
    },
    'routing.emergency-target-matrix'=>static function (): void {
        $f = new F(); $e = $f->entry(); $r = $f->request(['capabilities'=>[Capability::Text,Capability::Audio]]);
        $f->states = [$f->qualified($e,$r)];
        foreach (['provider'=>'scripted','account'=>'account-a','deployment'=>'ru-primary','model'=>'synthetic-revision-1','region'=>'RU','tenant'=>'alpha','task'=>'teacher-text','audio'=>true] as $field=>$value) {
            $f->rules = [F::rule($field,$value)];
            H::check($f->router()->route($r,$f->now())->rejected['ru-primary'] === [Reason::Emergency],$field);
            $f->rules = [F::rule($field,is_bool($value) ? false : 'different')];
            H::check($f->router()->route($r,$f->now())->selected === $e,$field);
        }
        $f->rules = [new EmergencyRule('incident','source',provider:'scripted',task:'other')];
        H::check($f->router()->route($r,$f->now())->selected === $e);
        H::rejects(static fn ()=>new EmergencyRule('incident','source'),InvalidArgumentException::class);
    },
    'routing.all-unavailable-and-tenant'=>static function (): void {
        $f = new F(); $f->states = [$f->qualified($f->entry(),null,['health'=>Health::Unknown])];
        H::check($f->router()->route($f->request(),$f->now())->outcome() === 'non_ai_text');
        $f->states = [$f->qualified($f->entry(['tenant'=>'beta']))];
        H::check($f->router()->route($f->request(),$f->now())->rejected['ru-primary'] === [Reason::Tenant]);
    },
];
