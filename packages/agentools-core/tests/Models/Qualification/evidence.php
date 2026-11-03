<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use AgenToolsPlatform\Core\Common\{IntegrityConflict, UtcInstant};
use AgenToolsPlatform\Core\Models\Qualification\{Evidence, EvidencePolicy, Requirements, Snapshot, Tuple};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Qualification\Fixture as Q;
use AgenToolsPlatform\Tests\Routing\Fixture as F;

H::run([
    'ECO-EVAL-001'=>static function (): void {
        $q = new Q(); $s = $q->snapshot(); $components = $s->tuple->components;
        foreach ($components as $key => $value) {
            $changed = $components; $changed[$key] = str_ends_with($key,'_hash') ? F::digest('f')->value : $value.'-changed';
            H::check(!$s->permits(new Tuple($s->ref->tenant,$changed,$s->tuple->suiteVersions),$s->policy,$q->f->now(),$q->f->hasher),$key);
        }
        H::check(!$s->permits(new Tuple($s->ref->tenant,$components,['technical/v2'=>F::digest('f')->value]),$s->policy,$q->f->now(),$q->f->hasher));
    },
    'ECO-EVAL-002'=>static function (): void {
        $q = new Q(); H::check($q->snapshot()->decision === 'qualified');
        H::check($q->snapshot(policy:$q->policy('educational'))->decision === 'insufficient_evidence');
        H::check($q->snapshot(policy:$q->policy('educational'),domain:'pass',scorers:['technical.hard'=>'pass'])->decision === 'insufficient_evidence');
        H::check($q->snapshot()->evidence->domain === 'insufficient_evidence');
    },
    'ECO-EVAL-003'=>static function (): void {
        $q = new Q(); H::check($q->snapshot(policy:$q->policy('live-provider'))->decision === 'insufficient_evidence');
        $technical = $q->snapshot();
        try { $technical->evidence->assertClaim('live_provider_ready',$technical->policy); H::check(false,'Fake-only live claim accepted'); }
        catch (DomainException $error) { H::check($error->getMessage() === 'EVIDENCE_SCOPE_MISMATCH'); }
        $higherTier = $q->snapshot(kind:'live-provider',live:'pass');
        H::rejects(static fn()=>$higherTier->evidence->assertClaim('live_provider_ready',$higherTier->policy),DomainException::class);
        H::check($q->snapshot(policy:$q->policy('product-pilot'),live:'pass')->decision !== 'qualified');
        H::rejects(static fn()=>new Evidence(F::ref('bad'),F::digest(),F::digest(),'fixture',[],[],[]),InvalidArgumentException::class);
    },
    'ECO-EVAL-005'=>static function (): void {
        $q = new Q(); $s = $q->snapshot(executed:['A']);
        H::check($s->decision === 'insufficient_evidence' && $s->evidence->exitCode($s->policy) === 1);
        foreach ([[],['A','A'],['A','B','extra']] as $cases) { H::check($q->snapshot(executed:$cases)->decision === 'insufficient_evidence'); }
        $raw = file_get_contents(dirname(__DIR__,5).'/fixtures/platform/v1/qualification/negative-insufficient-evidence.json');
        H::check($raw !== false); $doc = json_decode($raw === false ? '' : $raw,true,flags:JSON_THROW_ON_ERROR);
        $doc = \AgenToolsPlatform\Core\Models\Qualification\Data::object($doc);
        H::check(($doc['exit_code'] ?? null) === 1 && ($doc['suite_status'] ?? null) === 'insufficient_evidence');
        $sample = Snapshot::fromArray(\AgenToolsPlatform\Core\Models\Qualification\Data::object($doc['snapshot'] ?? null),$q->f->hasher);
        H::check($sample->decision === 'insufficient_evidence');
    },
    'ECO-EVAL-006'=>static function (): void {
        $q = new Q(); $technical = $q->snapshot(); $live = $q->snapshot(policy:$q->policy('live-provider'));
        H::check($live->evidence->live === 'not_run' && $live->decision === 'insufficient_evidence');
        H::check($technical->decision === 'qualified' && $technical->evidence->sourceHash->value === $live->evidence->sourceHash->value);
    },
    'V2-P12-2-TUPLE-POS'=>static function (): void {
        $q = new Q(); $s = $q->snapshot(); H::check($s->permits($q->tuple(),$q->policy(),$q->f->now(),$q->f->hasher));
        H::check(Snapshot::fromArray($s->toArray(),$q->f->hasher)->toArray() === $s->toArray());
        H::check(!$s->permits($q->tuple(),$q->policy(),new UtcInstant('2026-12-01T00:00:00Z'),$q->f->hasher));
        H::check(!$s->permits($q->tuple(),$q->policy(),new UtcInstant('2026-10-01T00:00:00Z'),$q->f->hasher));
    },
    'V2-P12-2-TIER-POS'=>static function (): void {
        $q = new Q(); foreach (['contract-scripted','isolated-postgres','local-host-e2e'] as $tier) { H::check($q->snapshot(kind:$tier)->decision === 'qualified'); }
        foreach (['design-schema','unit'] as $tier) { H::check($q->snapshot(kind:$tier)->decision === 'insufficient_evidence'); }
    },
    'V2ALL-EVAL-NEGATIVE'=>static function (): void {
        $q = new Q(); H::check($q->snapshot(scorers:['technical.hard'=>'fail'])->decision === 'rejected');
        H::check($q->snapshot(scorers:['technical.hard'=>'not_run'])->decision === 'insufficient_evidence');
        H::check($q->snapshot(scorers:['owner.domain'=>'pass'])->decision === 'insufficient_evidence');
        H::check($q->snapshot(policy:$q->policy('live-provider'),kind:'live-provider',live:'fail')->decision === 'rejected');
        $s = $q->snapshot(); $private = new Evidence(F::ref('private'),F::digest(),$s->tuple->hash($q->f->hasher),'contract-scripted',['A','B'],['A','B'],['technical.hard'=>'pass'],publicSyntheticCorpus:false);
        H::check($private->decision($s->policy) === 'rejected');
        $data = $s->toArray(); $data['decision'] = 'rejected'; H::rejects(static fn()=>Snapshot::fromArray($data,$q->f->hasher),IntegrityConflict::class);
        H::rejects(static fn()=>new EvidencePolicy(F::ref('empty'),[],['technical.hard'],['contract-scripted']),InvalidArgumentException::class);
    },
]);
