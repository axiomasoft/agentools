<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Profiles\UseBoundary;
use AgenToolsPlatform\Core\Profiles\ResourceRef;
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Core\Common\CanonicalJson;

/** Shared publication/load checks; no permissive fallback for unknown counts or content. */
final class Checks
{
    public static function entry(PublishedSkill $entry, ResourceRef $pin, TenantId $tenant): void
    {
        $pin->owner->assertVisible($tenant); $entry->qualification->ref->owner->assertVisible($tenant);
        if ($entry->manifest->dependency->ref->toArray() !== $pin->toArray()) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('HASH_MISMATCH'); }
    }
    public static function metadata(SelectionRequest $request, SkillManifest $manifest, TokenCounter $counter, CanonicalJson $json): void
    {
        $manifest->assertIntegrity($json);
        $manifest->dependency->ref->owner->assertVisible($request->run->tenant);
        $manifest->compatibility->definition->owner->assertVisible($request->run->tenant);
        $manifest->compatibility->profile->owner->assertVisible($request->run->tenant);
        foreach ($manifest->fragments as $fragment) { $fragment->owner->assertVisible($request->run->tenant); }
        if ($manifest->purpose !== $request->purpose || !in_array($request->task,$manifest->triggers,true)) {
            throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('TASK_MISMATCH');
        }
        if (!$manifest->compatibility->matches($request->scope->bundle)) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('INCOMPATIBLE_SKILL'); }
        if ($counter->deployment()->toArray() !== $request->scope->deployment->toArray()) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('COUNTER_DEPLOYMENT_MISMATCH'); }
        if ($request->tokenCap === 0 || $manifest->tokenBudget > $request->tokenCap) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('TOKEN_BUDGET_EXHAUSTED','denied'); }
    }
    /**
     * @return list<Fragment>
     */
    public static function load(SelectionRequest $request, SkillManifest $manifest, ContentStore $store,
        Policy $policy, TokenCounter $counter, UseBoundary $boundary, CanonicalJson $json): array
    {
        $result=[]; $tokens=0;
        foreach ($manifest->fragments as $pin) {
            $fragment=$store->load($pin)->verified($json);
            if ($pin->toArray() !== $fragment->ref->toArray()) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('HASH_MISMATCH'); }
            if ($fragment->kind !== ContentKind::Fragment) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('FORBIDDEN_CONTENT_KIND','denied'); }
            $policy->fragment($request,$manifest,$fragment,$boundary);
            $estimate=$counter->estimate($fragment->text);
            if ($estimate->upperBound === null) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('TOKEN_COUNT_UNKNOWN','unknown'); }
            $tokens+=$estimate->upperBound;
            if ($tokens > $manifest->tokenBudget || $tokens > $request->tokenCap) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('TOKEN_BUDGET_EXCEEDED','denied'); }
            $result[]=$fragment;
        }
        return $result;
    }
    public static function qualification(PublishedSkill $skill, QualificationTuple $tuple): void
    {
        if ($skill->qualification->tupleHash->value !== $tuple->hash->value) {
            throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('QUALIFICATION_TUPLE_MISMATCH','denied');
        }
    }
    public static function failure(\Throwable $error): SelectionFailure
    {
        if ($error instanceof SelectionFailure) { return $error; }
        if ($error instanceof \AgenToolsPlatform\Core\Common\IntegrityConflict) { return new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('HASH_MISMATCH'); }
        if ($error instanceof \AgenToolsPlatform\Core\Common\ContractViolation) { return new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('ACCESS_DENIED','denied'); }
        return new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('POLICY_OR_STORAGE_UNKNOWN','unknown');
    }
}
