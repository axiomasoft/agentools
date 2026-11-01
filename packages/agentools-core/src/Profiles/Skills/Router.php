<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\CanonicalJson;
use AgenToolsPlatform\Core\Profiles\UseBoundary;
use AgenToolsPlatform\Core\Profiles\Composition\Composer;

/** No model judge or effect port. Known host routes are exact; fallback selection is deterministic. */
final readonly class Router
{
    public function __construct(private ManifestCatalog $catalog, private ContentStore $store, private Policy $policy,
        private TokenCounter $counter, private Composer $composer, private CanonicalJson $json) {}

    /**
     * @phpstan-impure Current authorization/status/qualification checks, metadata only.
     */
    public function shortlist(SelectionRequest $request): Shortlist|SelectionFailure
    {
        try {
            $this->policy->authorize($request,UseBoundary::Invocation);
            if ($request->tokenCap === 0) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('TOKEN_BUDGET_EXHAUSTED','denied'); }
            $entries=[];
            foreach ($request->routes !== [] ? $request->routes : $request->pins as $pin) {
                $entry=$this->catalog->get($pin,$request->run->tenant); Checks::entry($entry,$pin,$request->run->tenant); $manifest=$entry->manifest;
                $status=$this->policy->status($request,$entry,UseBoundary::Invocation);
                if ($status === PublicationStatus::Unknown) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('STATUS_UNKNOWN','unknown'); }
                if ($status !== PublicationStatus::Published) {
                    if ($request->routes !== []) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('SKILL_'.strtoupper($status->value),'denied'); }
                    continue;
                }
                try {
                    Checks::metadata($request,$manifest,$this->counter,$this->json);
                    $this->policy->metadata($request,$manifest,UseBoundary::Invocation);
                    $projection=Projection::prepare($request,[$manifest],$this->composer,$this->json,UseBoundary::Invocation);
                    $tuple=new \AgenToolsPlatform\Core\Profiles\Skills\QualificationTuple($request,$manifest,$projection,$this->counter->revision(),$this->json);
                    Checks::qualification($entry,$tuple); $this->policy->assertQualification($request,$entry,$tuple,UseBoundary::Invocation);
                } catch (SelectionFailure $error) {
                    if ($request->routes !== [] || !in_array($error->failureCode,['TASK_MISMATCH','INCOMPATIBLE_SKILL','TOKEN_BUDGET_EXHAUSTED','TOOL_INTERSECTION_EMPTY'],true)) { throw $error; }
                    continue;
                } catch (\AgenToolsPlatform\Core\Common\ContractViolation $error) {
                    if ($request->routes !== [] || $error->errorCode !== \AgenToolsPlatform\Core\Common\ErrorCode::AccessDenied) { throw $error; }
                    continue;
                }
                $entries[]=$entry;
                if (count($entries) === $request->shortlistLimit) { break; }
            }
            if ($entries === []) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('NO_ELIGIBLE_SKILL','denied'); }
            return new \AgenToolsPlatform\Core\Profiles\Skills\Shortlist($entries,$request->hash($this->json,$this->counter->revision()),$request->shortlistLimit);
        } catch (\Throwable $error) { return Checks::failure($error); }
    }

    /**
     * @phpstan-impure Bounded deterministic choice; no full fragment is loaded here.
     */
    public function select(SelectionRequest $request): SelectionSnapshot|MissingContext|SelectionFailure
    {
        $shortlist=$this->shortlist($request);
        if ($shortlist instanceof SelectionFailure) { return $shortlist; }
        try {
            $missing=[]; $refs=[];
            foreach ($shortlist->entries as $entry) {
                $needed=array_values(array_diff($entry->manifest->requiredContext,$request->contextKinds));
                if ($needed !== []) { $missing=Bounds::names(array_merge($missing,$needed),256); $refs[]=$entry->manifest->dependency->ref; continue; }
                $projection=Projection::prepare($request,[$entry->manifest],$this->composer,$this->json,UseBoundary::Invocation);
                $tuple=new \AgenToolsPlatform\Core\Profiles\Skills\QualificationTuple($request,$entry->manifest,$projection,$this->counter->revision(),$this->json);
                return new \AgenToolsPlatform\Core\Profiles\Skills\SelectionSnapshot($request->run,$shortlist->routingHash,$entry->manifest->dependency->ref,$tuple,$this->counter->revision(),$this->json);
            }
            return new \AgenToolsPlatform\Core\Profiles\Skills\MissingContext($missing,$refs);
        } catch (\Throwable $error) { return Checks::failure($error); }
    }

    /**
     * @phpstan-impure Recheck current guards over the stored selection; never reselect or substitute latest.
     */
    public function load(SelectionRequest $request, SelectionSnapshot $snapshot, UseBoundary $boundary = UseBoundary::Invocation): PreparedSelection|MissingContext|SelectionFailure
    {
        try {
            if (!in_array($boundary,[UseBoundary::Invocation,UseBoundary::Action,UseBoundary::Disclosure],true)) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('BOUNDARY_INVALID'); }
            $snapshot->assertRun($request->run);
            if ($snapshot->routingHash->value !== $request->hash($this->json,$this->counter->revision())->value) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('SELECTION_INPUT_MISMATCH'); }
            $this->policy->authorize($request,$boundary);
            $pins=[]; foreach ($request->pins as $pin) { $pins[$pin->key()]=$pin->version->hash->value; }
            if (($pins[$snapshot->skill->key()] ?? null) !== $snapshot->skill->version->hash->value
                || ($request->routes !== [] && $request->routes[0]->toArray() !== $snapshot->skill->toArray())) {
                throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('ROUTE_NOT_PINNED');
            }
            $entry=$this->catalog->get($snapshot->skill,$request->run->tenant);
            Checks::entry($entry,$snapshot->skill,$request->run->tenant);
            $this->assertCurrent($request,$entry,$boundary);
            Checks::metadata($request,$entry->manifest,$this->counter,$this->json); $this->policy->metadata($request,$entry->manifest,$boundary);
            $needed=array_values(array_diff($entry->manifest->requiredContext,$request->contextKinds));
            if ($needed !== []) { return new \AgenToolsPlatform\Core\Profiles\Skills\MissingContext($needed,[$snapshot->skill]); }
            $projection=Projection::prepare($request,[$entry->manifest],$this->composer,$this->json,$boundary);
            $tuple=new \AgenToolsPlatform\Core\Profiles\Skills\QualificationTuple($request,$entry->manifest,$projection,$this->counter->revision(),$this->json);
            if ($tuple->hash->value !== $snapshot->tuple->hash->value) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('QUALIFICATION_TUPLE_MISMATCH'); }
            Checks::qualification($entry,$tuple); $this->policy->assertQualification($request,$entry,$tuple,$boundary);
            $fragments=Checks::load($request,$entry->manifest,$this->store,$this->policy,$this->counter,$boundary,$this->json);
            // Revocation or permission loss during retrieval blocks release to the model/context pipeline.
            $this->policy->authorize($request,$boundary); $this->assertCurrent($request,$entry,$boundary);
            $this->policy->assertQualification($request,$entry,$tuple,$boundary);
            $this->policy->metadata($request,$entry->manifest,$boundary);
            foreach ($fragments as $fragment) { $this->policy->fragment($request,$entry->manifest,$fragment,$boundary); }
            if ($snapshot->counter->toArray() !== $this->counter->revision()->toArray()) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('COUNTER_CHANGED','unknown'); }
            return new \AgenToolsPlatform\Core\Profiles\Skills\PreparedSelection($snapshot,$fragments,$projection);
        } catch (\Throwable $error) { return Checks::failure($error); }
    }

    /**
     * Historical refs/hashes are retained; the current revoked marker is an overlay, not a content edit.
     * @return array<string, mixed>|SelectionFailure
     * @phpstan-impure
     */
    public function audit(SelectionRequest $request, SelectionSnapshot $snapshot): array|SelectionFailure
    {
        try {
            $snapshot->assertRun($request->run); $this->policy->authorize($request,UseBoundary::Disclosure);
            $entry=$this->catalog->get($snapshot->skill,$request->run->tenant);
            Checks::entry($entry,$snapshot->skill,$request->run->tenant);
            return ['selection'=>$snapshot->jsonSerialize(),'current_status'=>$this->policy->status($request,$entry,UseBoundary::Disclosure)->value];
        } catch (\Throwable $error) { return Checks::failure($error); }
    }
    private function assertCurrent(SelectionRequest $request, PublishedSkill $entry, UseBoundary $boundary): void
    {
        $status=$this->policy->status($request,$entry,$boundary);
        if ($status === PublicationStatus::Unknown) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('STATUS_UNKNOWN','unknown'); }
        if (!in_array($status,[PublicationStatus::Published,PublicationStatus::Deprecated],true)) {
            throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('SKILL_'.strtoupper($status->value),'denied');
        }
    }
}
