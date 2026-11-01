<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;

/** Exact safety-relevant tuple, not a model-brand qualification. No scores or thresholds are invented. */
final readonly class QualificationTuple
{
    public PayloadHash $hash;
    public PayloadHash $promptsHash;
    public PayloadHash $toolsHash;
    public function __construct(SelectionRequest $request, SkillManifest $skill, Projection $projection, DefinitionRef $counter, CanonicalJson $json)
    {
        $this->promptsHash=new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.hash('sha256',$json->canonicalize(array_map(static fn (FragmentRef $r): array => $r->toArray(),$skill->fragments))));
        $this->toolsHash=new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.hash('sha256',$json->canonicalize($projection->toolViews())));
        $this->hash=new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.hash('sha256',$json->canonicalize([
            'format'=>'skill-qualification-tuple/v1','deployment'=>$request->scope->deployment->toArray(),
            'model_revision'=>$request->scope->model->toArray(),'profile_bundle'=>$request->scope->bundle->bundle->toArray(),
            'task'=>$request->task,'purpose'=>$request->purpose,'suite_versions'=>array_map(static fn (DefinitionRef $r): array => $r->toArray(),$request->scope->suiteVersions),
            'prompt_hash'=>$this->promptsHash->value,'skill'=>$skill->dependency->ref->toArray(),'tool_schema_hash'=>$this->toolsHash->value,
            'corpus_hash'=>$request->scope->corpusHash->value,'counter'=>$counter->toArray()])));
    }
}
