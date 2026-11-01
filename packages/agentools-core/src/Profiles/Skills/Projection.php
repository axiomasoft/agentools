<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\CanonicalJson;
use AgenToolsPlatform\Core\Profiles\{OwnerScope, UseBoundary};
use AgenToolsPlatform\Core\Profiles\Composition\{CompiledComposition, Composer, HardConstraints, OperationalOverrides, OperationalRole, ProfileConflict, RegisteredTool, SafetyFloor};

final readonly class Projection
{
    /**
     * @param list<RegisteredTool> $tools
     */
    private function __construct(public CompiledComposition $composition, public array $tools) {}

    /**
     * Selected skills only narrow the existing session limits; token caps are not money/admission units.
     * @param list<SkillManifest> $skills
     */
    public static function prepare(SelectionRequest $request, array $skills, Composer $composer, CanonicalJson $json, UseBoundary $boundary): self
    {
        $session=$request->scope->session; $hard=$session->hard; $tools=$hard->tools; $safety=$hard->safety->value; $refs=[];
        foreach ($skills as $skill) {
            if (!$skill->compatibility->matches($request->scope->bundle)) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('INCOMPATIBLE_SKILL'); }
            $allowed=[];
            foreach ($skill->tools as $tool) {
                $installed=$request->scope->pinned->input()->registry->tools[$tool->id] ?? null;
                if ($installed !== null && $installed->definition->toArray() === $tool->toArray()) { $allowed[]=$tool->id; }
            }
            $tools=array_values(array_intersect($tools,$allowed)); $safety=max($safety,$skill->safety->value); $refs[]=$skill->dependency->ref->toArray();
        }
        if ($tools === []) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('TOOL_INTERSECTION_EMPTY','denied'); }
        $limits=new \AgenToolsPlatform\Core\Profiles\Composition\HardConstraints($hard->regions,$hard->deployments,$hard->dataScopes,$tools,
            $hard->budgetUnits,$hard->confirmation,SafetyFloor::from($safety),$hard->denied,$hard->capabilities,$hard->requiredCapabilities);
        $version=hash('sha256',$json->canonicalize(['session'=>$session->dependency->toArray(),'skills'=>$refs]));
        $merged=new \AgenToolsPlatform\Core\Profiles\Composition\OperationalOverrides(OwnerScope::tenant($request->run->tenant),'skills.selection',$version,
            OperationalRole::Session,$limits,$session->dependency->provenance,$json,$session->preference);
        $result=$composer->prepare($request->scope->pinned,$request->scope->host,$merged,$request->scope->live,$boundary);
        if ($result instanceof ProfileConflict) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure($result->code,'denied'); }
        $restrictions=json_decode($result->compiled->restrictionsJson,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($restrictions) || !is_array($restrictions['tools'] ?? null)) { throw new \LogicException('Typed composition output required'); }
        if (!is_array($restrictions['deployments'] ?? null) || !in_array($request->scope->deployment->version->id,$restrictions['deployments'],true)) {
            throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('DEPLOYMENT_UNAVAILABLE','denied');
        }
        $effective=json_decode($result->compiled->effectiveJson,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($effective) || (($effective['selected_deployment'] ?? null) !== null
            && $effective['selected_deployment'] !== $request->scope->deployment->version->id)) {
            throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('DEPLOYMENT_SELECTION_MISMATCH','denied');
        }
        $registered=[];
        foreach ($restrictions['tools'] as $id) {
            if (!is_string($id) || !isset($request->scope->pinned->input()->registry->tools[$id])) { throw new \LogicException('Pinned tool projection required'); }
            $registered[]=$request->scope->pinned->input()->registry->tools[$id];
        }
        return new \AgenToolsPlatform\Core\Profiles\Skills\Projection($result,$registered);
    }
    /**
     * @return list<array<string, mixed>>
     */
    public function toolViews(): array { return array_map(static fn (RegisteredTool $t): array => $t->toArray(),$this->tools); }
}
