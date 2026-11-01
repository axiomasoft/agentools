<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\CanonicalJson;
use AgenToolsPlatform\Core\Profiles\UseBoundary;
use AgenToolsPlatform\Core\Profiles\Composition\Composer;

/** Produces an immutable publication record; the host owns its durable publication storage/assignment. */
final readonly class Publisher
{
    public function __construct(private ContentStore $store, private Policy $policy, private TokenCounter $counter,
        private Composer $composer, private CanonicalJson $json) {}

    /**
     * @phpstan-impure Host publication/evaluation/content policy and exact storage reads are required.
     */
    public function publish(SelectionRequest $request, SkillManifest $manifest): PublishedSkill|SelectionFailure
    {
        try {
            $this->policy->authorizePublication($request,$manifest);
            Checks::metadata($request,$manifest,$this->counter,$this->json);
            $this->policy->metadata($request,$manifest,UseBoundary::NewSession);
            $projection=Projection::prepare($request,[$manifest],$this->composer,$this->json,UseBoundary::Invocation);
            Checks::load($request,$manifest,$this->store,$this->policy,$this->counter,UseBoundary::NewSession,$this->json);
            $tuple=new \AgenToolsPlatform\Core\Profiles\Skills\QualificationTuple($request,$manifest,$projection,$this->counter->revision(),$this->json);
            $qualification=$this->policy->qualify($request,$manifest,$tuple);
            $qualification->ref->owner->assertVisible($request->run->tenant);
            $qualification->tupleHash->assertSame($tuple->hash);
            $this->policy->authorizePublication($request,$manifest);
            return new \AgenToolsPlatform\Core\Profiles\Skills\PublishedSkill($manifest,$qualification);
        } catch (\Throwable $error) { return Checks::failure($error); }
    }
}
