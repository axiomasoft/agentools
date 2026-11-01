<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Core\Profiles\{DefinitionRef, OwnerScope};
use AgenToolsPlatform\Core\Profiles\Composition\OperationalRole;
use AgenToolsPlatform\Core\Profiles\Skills\{ContentKind, ManifestCodec, MissingContext, PublishedSkill, RuntimeScope, SelectionRequest, Shortlist, TokenEstimate};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Profiles\Merge\Fixture as Merge;
use AgenToolsPlatform\Tests\Profiles\Skills\{Capture, Fixture as F, Proof};

Proof::run([
    'CM-007'=>static function (): void {
        $f=new F(); $selected=null;
        for ($i=0;$i<100;++$i) { $entry=$f->publish('skill.'.str_pad((string)$i,3,'0',STR_PAD_LEFT)); if ($i===42) { $selected=$entry; } }
        if ($selected === null) { throw new RuntimeException('Selected fixture required'); }
        $f->store->resetLoads(); $evaluations=$f->policy->qualificationCalls;
        $request=$f->request(routes:[$selected->manifest->dependency->ref]);
        $shortlist=$f->router->shortlist($request);
        H::check($shortlist instanceof Shortlist && count($shortlist->entries)===1 && $f->store->loaded()===[]);
        $snapshot=F::selected($f->router->select($request));
        $prepared=F::prepared($f->router->load($request,$snapshot));
        $capture=new Capture(); $capture->send($prepared,$request,$f); $model=$capture->received;
        if ($model === null || !$shortlist instanceof Shortlist) { throw new RuntimeException('Captured request required'); }
        H::check(count($f->store->loaded())===1 && $f->store->loaded()[0]->toArray()===$selected->manifest->fragments[0]->toArray());
        H::check(count($model->messages)===1 && count($model->messages[0]->parts)===1);
        H::check($model->messages[0]->parts[0]->text==='Only full fragment skill.042@1');
        $names=array_map(static fn ($tool): string => $tool->name,$model->tools);
        H::check($names===['search'] && !str_contains(json_encode($model,JSON_THROW_ON_ERROR),'send_only'));
        H::check(!str_contains(json_encode($shortlist,JSON_THROW_ON_ERROR),'Only full fragment'));
        H::check($f->policy->qualificationCalls===$evaluations,'No evaluation judge may run during selection/load');
        Proof::$observations['CM-007']=['registered'=>count($f->tenantCatalog->entries),'shortlist'=>count($shortlist->entries),
            'selected'=>count([$snapshot->skill]),'fragment_loads'=>count($f->store->loaded()),'transport_calls'=>$capture->calls,'tools'=>$names,
            'qualification_calls_during_route'=>$f->policy->qualificationCalls-$evaluations];
    },
    'CM-008'=>static function (): void {
        $f=new F(); $entry=$f->publish(); $request=$f->request(routes:[$entry->manifest->dependency->ref]); $snapshot=F::selected($f->router->select($request));
        $f->store->put($f->fragment(version:'2',text:'LATEST_MUST_NOT_BE_LOADED'));
        $f->store->corrupted=$f->fragment(text:'corrupt cached bytes'); $f->store->resetLoads();
        $result=$f->router->load($request,$snapshot); F::failure($result,'HASH_MISMATCH');
        H::check(count($f->store->loaded())===1 && $f->store->loaded()[0]->version->version==='1');
        Proof::$observations['CM-008']=['code'=>$result instanceof \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure?$result->failureCode:'unexpected',
            'loads'=>count($f->store->loaded()),'loaded_versions'=>array_map(static fn ($r): string => $r->version->version,$f->store->loaded()),'latest_substituted'=>false];
    },
    'V2ALL-SKILL-POSITIVE'=>static function (): void {
        $f=new F(); $entry=$f->publish(); $codec=new ManifestCodec($f->merge->json); $manifest=$entry->manifest;
        H::check($codec->decode($codec->encode($manifest))->dependency->toArray()===$manifest->dependency->toArray());
        H::check($entry->view()['publication_status']==='published' && $entry->qualification->evidenceTier==='contract-scripted');
        H::check($manifest->purpose==='support.read' && $manifest->requiredContext===['goal'] && $manifest->tokenBudget===256);
        H::check($manifest->rubrics===['rubric:support-read'] && $manifest->evaluations===['evaluation:task-support']);
        H::check($manifest->dependency->ref->owner->kind==='tenant' && $f->policy->qualificationCalls===1);
        $request=$f->request(); $snapshot=F::selected($f->router->select($request));
        F::prepared($f->router->load($request,$snapshot));
        H::check($snapshot->tuple->hash->value===$entry->qualification->tupleHash->value);
    },
    'V2ALL-SKILL-NEGATIVE'=>static function (): void {
        $f=new F(); $manifest=$f->manifest(); $codec=new ManifestCodec($f->merge->json); $rejected=[];
        foreach (['secret','history','corpus','curriculum','tool_catalogue'] as $field) {
            $wire=$manifest->toArray(); $wire[$field]='forbidden-inline-payload';
            H::rejects(static fn () => $codec->decode(json_encode($wire,JSON_THROW_ON_ERROR)),InvalidArgumentException::class); $rejected[]=$field;
        }
        foreach ([ContentKind::Secret,ContentKind::History,ContentKind::Corpus,ContentKind::Curriculum,ContentKind::ToolCatalogue] as $kind) {
            $fragment=$f->fragment('skill.'.$kind->value,kind:$kind); $f->store->put($fragment); $candidate=$f->manifest('skill.'.$kind->value,fragment:$fragment);
            F::failure($f->publisher->publish($f->request(pins:[$candidate->dependency->ref]),$candidate),'FORBIDDEN_CONTENT_KIND');
        }
        foreach (['secret','history','corpus','curriculum','tool_catalogue'] as $kind) {
            $secret=$f->fragment('skill.disguised.'.$kind,text:$kind.'_sentinel disguised as skill data'); $f->store->put($secret); $candidate=$f->manifest('skill.disguised.'.$kind,fragment:$secret);
            F::failure($f->publisher->publish($f->request(pins:[$candidate->dependency->ref]),$candidate),'ACCESS_DENIED');
        }
        H::check($f->policy->qualificationCalls===0);
        Proof::$observations['V2ALL-SKILL-NEGATIVE']=['rejected_manifest_fields'=>$rejected,'forbidden_fragment_kinds'=>5,'qualified_publications'=>$f->policy->qualificationCalls];
    },
    'skills.missing-context'=>static function (): void {
        $f=new F(); $entry=$f->publish(); $f->store->resetLoads(); $counter=$f->counter->calls;
        $result=$f->router->select($f->request(routes:[$entry->manifest->dependency->ref],context:[]));
        H::check($result instanceof MissingContext && $result->kinds===['goal']);
        H::check($f->store->loaded()===[] && $f->counter->calls===$counter);
    },
    'skills.reproducible-host-route'=>static function (): void {
        $f=new F(); $b=$f->publish('skill.b'); $a=$f->publish('skill.a');
        $one=F::selected($f->router->select($f->request(run:'run-a'))); $two=F::selected($f->router->select($f->request(run:'run-b')));
        H::check($one->hash->value===$two->hash->value && json_encode($one,JSON_THROW_ON_ERROR)===json_encode($two,JSON_THROW_ON_ERROR));
        H::check($one->skill->toArray()===$a->manifest->dependency->ref->toArray());
        $known=F::selected($f->router->select($f->request(routes:[$b->manifest->dependency->ref])));
        H::check($known->skill->toArray()===$b->manifest->dependency->ref->toArray());
        $request=$f->request(routes:[$b->manifest->dependency->ref]);
        $mismatch=new SelectionRequest($request->run,$request->session,$request->scope,$request->purpose,'other.task',$request->contextKinds,$request->contextHash,$request->pins,$request->routes);
        F::failure($f->router->select($mismatch),'TASK_MISMATCH');
    },
    'skills.tool-versions-availability'=>static function (): void {
        $f=new F(); $entry=$f->publish(); $f->store->resetLoads();
        $scope=$f->scope;
        $blocked=new RuntimeScope($scope->bundle,$scope->pinned,$scope->host,$scope->session,
            $f->merge->operational(OperationalRole::Live,$f->merge->hard(tools:['send'])),$scope->deployment,$scope->model,$scope->suiteVersions,$scope->corpusHash);
        F::failure($f->router->select($f->request(routes:[$entry->manifest->dependency->ref],scope:$blocked)),'TOOL_INTERSECTION_EMPTY');
        H::check($f->store->loaded()===[]);
        $deploymentBlocked=new RuntimeScope($scope->bundle,$scope->pinned,$scope->host,$scope->session,
            $f->merge->operational(OperationalRole::Live,$f->merge->hard(deployments:['deployment.b'])),
            $scope->deployment,$scope->model,$scope->suiteVersions,$scope->corpusHash);
        F::failure($f->router->select($f->request(routes:[$entry->manifest->dependency->ref],scope:$deploymentBlocked)),'DEPLOYMENT_UNAVAILABLE');
        H::rejects(static fn () => new RuntimeScope($scope->bundle,$scope->pinned,$scope->host,$scope->session,$scope->live,
            new \AgenToolsPlatform\Core\Profiles\ResourceRef($scope->deployment->owner,$scope->deployment->kind,$scope->deployment->version->id,'2',Merge::hash('different-deployment')),
            $scope->model,$scope->suiteVersions,$scope->corpusHash),\AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure::class);
        $wrong=$f->manifest('skill.wrong',tools:[new DefinitionRef('search','2',Merge::hash('wrong-version'))]);
        F::failure($f->publisher->publish($f->request(pins:[$wrong->dependency->ref]),$wrong),'TOOL_INTERSECTION_EMPTY');
        H::check($f->store->loaded()===[]);
    },
    'skills.token-bounds'=>static function (): void {
        $f=new F(); $entry=$f->publish(); $request=$f->request(routes:[$entry->manifest->dependency->ref]); $snapshot=F::selected($f->router->select($request));
        $f->counter->unknown=true; F::failure($f->router->load($request,$snapshot),'TOKEN_COUNT_UNKNOWN');
        $f->counter->unknown=false; $f->counter->oversized=true; F::failure($f->router->load($request,$snapshot),'TOKEN_BUDGET_EXCEEDED');
        F::failure($f->router->select($f->request(tokens:0)),'TOKEN_BUDGET_EXHAUSTED');
        H::rejects(static fn () => TokenEstimate::bounded(0),InvalidArgumentException::class);
    },
    'skills.permissions-shortlist'=>static function (): void {
        $f=new F(); $a=$f->publish('skill.a'); $b=$f->publish('skill.b');
        $f->policy->deniedMetadata['skill.a']=true; $f->store->resetLoads();
        $snapshot=F::selected($f->router->select($f->request()));
        H::check($snapshot->skill->toArray()===$b->manifest->dependency->ref->toArray());
        F::failure($f->router->select($f->request(routes:[$a->manifest->dependency->ref])),'ACCESS_DENIED');
        H::check($f->store->loaded()===[]);
    },
    'skills.metadata-bounds'=>static function (): void {
        $f=new F(); $tools=[];
        for ($i=0;$i<17;++$i) { $tools[]=new DefinitionRef('tool.'.$i,'1',Merge::hash('tool'.$i)); }
        H::rejects(static fn () => $f->manifest(tools:$tools),InvalidArgumentException::class);
        H::rejects(static fn () => $f->manifest(context:[str_repeat('x',1024)]),InvalidArgumentException::class);
        H::rejects(static fn () => $f->manifest(budget:16385),InvalidArgumentException::class);
        H::rejects(static fn () => $f->fragment(text:str_repeat('x',65537)),InvalidArgumentException::class);
        $fragment=$f->fragment(); $f->store->put($fragment);
        H::rejects(static fn () => $f->store->put($f->fragment(text:'same version, different content')),IntegrityConflict::class);
    },
]);
