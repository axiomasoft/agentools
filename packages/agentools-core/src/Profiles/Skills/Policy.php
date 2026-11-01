<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Profiles\UseBoundary;

/** Mandatory authenticated host port. No permissive production policy or evaluator is supplied. */
interface Policy
{
    /** Current tenant/actor/purpose, P7.1 session pin and live policy revision checks. */
    public function authorize(SelectionRequest $request, UseBoundary $boundary): void;
    /** Explicit platform/tenant publication permission, before content is loaded. */
    public function authorizePublication(SelectionRequest $request, SkillManifest $manifest): void;
    public function status(SelectionRequest $request, PublishedSkill $skill, UseBoundary $boundary): PublicationStatus;
    /** Current audience/metadata policy; rejects sensitive values disguised as metadata identifiers. */
    public function metadata(SelectionRequest $request, SkillManifest $manifest, UseBoundary $boundary): void;
    /** Current semantic content/classification/rights policy. Text is always untrusted data. */
    public function fragment(SelectionRequest $request, SkillManifest $manifest, Fragment $fragment, UseBoundary $boundary): void;
    /** Actual deterministic AND task-specific evaluation evidence for this exact tuple, once at publish. */
    public function qualify(SelectionRequest $request, SkillManifest $manifest, QualificationTuple $tuple): Qualification;
    /** Check tuple binding, revocation and expiry; do not run a second model judge each turn. */
    public function assertQualification(SelectionRequest $request, PublishedSkill $skill, QualificationTuple $tuple, UseBoundary $boundary): void;
}
