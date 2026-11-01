<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Common\{CanonicalJson, ContractViolation, IntegrityConflict};
use AgenToolsPlatform\Core\Profiles\{CompiledProfile, Dependency, UseBoundary};

/** Deterministic security composition before LLM use; it has no model or effect port. */
final readonly class Composer
{
    public function __construct(private CanonicalJson $json, private MaterialAccessPolicy $materials) {}

    /**
     * @phpstan-impure Current material owner policy must run on every call.
     */
    public function compile(CompilationInput $input): CompiledComposition|ProfileConflict
    {
        return $this->resolve($input, [], UseBoundary::NewSession);
    }

    /**
     * @phpstan-impure Current overrides/material access are never cached as permanent permission.
     */
    public function prepare(CompiledComposition $pinned, OperationalOverrides $host, OperationalOverrides $session,
        OperationalOverrides $live, UseBoundary $boundary): CompiledComposition|ProfileConflict
    {
        if (!in_array($boundary, [UseBoundary::Invocation, UseBoundary::Action, UseBoundary::Disclosure], true)) {
            throw new \InvalidArgumentException('Execution boundary required');
        }
        if ($host->role !== OperationalRole::Host || $session->role !== OperationalRole::Session || $live->role !== OperationalRole::Live) {
            return $this->conflict('OPERATIONAL_ROLES_INVALID', 'operational_overrides', [
                ['dependency'=>$host->dependency, 'value'=>$host->role->name],
                ['dependency'=>$session->dependency, 'value'=>$session->role->name],
                ['dependency'=>$live->dependency, 'value'=>$live->role->name]], 'Host, session and live limits must be explicit and distinct');
        }
        return $this->resolve($pinned->input(), [$host,$session,$live], $boundary);
    }

    /**
     * @param list<OperationalOverrides> $overrides
     */
    private function resolve(CompilationInput $input, array $overrides, UseBoundary $boundary): CompiledComposition|ProfileConflict
    {
        try { $dependencies = $input->dependencies(); }
        catch (IntegrityConflict) { return $this->conflict('DEPENDENCY_HASH_CONFLICT', 'dependencies', $this->sources($input, $overrides, 'dependencies'), 'One dependency identity has different content'); }
        $identities = [];
        foreach (array_merge($dependencies, array_map(static fn (OperationalOverrides $o): Dependency => $o->dependency, $overrides)) as $dependency) {
            $key = $dependency->ref->key();
            if (isset($identities[$key]) && $identities[$key]->toArray() !== $dependency->toArray()) {
                return $this->conflict('DEPENDENCY_HASH_CONFLICT', 'dependencies', [
                    ['dependency'=>$identities[$key], 'value'=>$identities[$key]->toArray()],
                    ['dependency'=>$dependency, 'value'=>$dependency->toArray()]], 'An operational override cannot relabel a pinned version');
            }
            $identities[$key] = $dependency;
            try { $dependency->ref->owner->assertVisible($input->tenant); }
            catch (ContractViolation) { return $this->conflict('OWNER_SCOPE_MISMATCH', 'owner', [['dependency'=>$dependency, 'value'=>$dependency->ref->owner->toArray()]], 'Foreign tenant content cannot be inherited'); }
        }
        $roles = []; $identities = [];
        foreach ($input->layers as $layer) {
            $ref = $layer->dependency->ref;
            if (isset($identities[$ref->key()]) || (in_array($layer->role, [LayerRole::Definition,LayerRole::Platform,LayerRole::Tenant,LayerRole::Profile], true) && isset($roles[$layer->role->value]))) {
                return $this->conflict('LAYER_ASSIGNMENT_AMBIGUOUS', 'layers', $this->sources($input, $overrides, 'layers'), 'Each root role and layer identity must have one assignment');
            }
            if (($layer->role === LayerRole::Platform && $ref->owner->kind !== 'platform')
                || ($layer->role === LayerRole::Tenant && $ref->owner->kind !== 'tenant')) {
                return $this->conflict('OWNER_ROLE_MISMATCH', 'owner', [['dependency'=>$layer->dependency, 'value'=>$ref->owner->toArray()]], 'Platform and tenant layers require explicit matching ownership tags');
            }
            $identities[$ref->key()] = true; $roles[$layer->role->value] = $layer;
        }
        foreach ([LayerRole::Definition,LayerRole::Platform,LayerRole::Tenant,LayerRole::Profile] as $role) {
            if (!isset($roles[$role->value])) { return $this->conflict('LAYER_REQUIRED', 'layers', $this->sources($input, $overrides, 'layers'), 'Definition, platform, tenant and profile layers are required'); }
        }
        $definition = $roles[LayerRole::Definition->value]; $profile = $roles[LayerRole::Profile->value];
        if ($definition->defaults === null || $definition->editable === null) {
            return $this->conflict('DEFINITION_SETTINGS_REQUIRED', 'defaults', [['dependency'=>$definition->dependency, 'value'=>null]], 'Code definition must declare defaults and editable style fields');
        }
        $constraints = array_merge(array_map(static fn (PolicyLayer $p): HardConstraints => $p->hard, $input->layers),
            array_map(static fn (OperationalOverrides $o): HardConstraints => $o->hard, $overrides));
        $first = $constraints[0];
        $sets = ['regions'=>$first->regions, 'deployments'=>array_keys($input->deployments), 'data_scopes'=>$first->dataScopes,
            'tools'=>array_keys($input->registry->tools), 'capabilities'=>$first->capabilities];
        $denied = ['regions'=>[], 'deployments'=>[], 'data_scopes'=>[], 'tools'=>[], 'capabilities'=>[]];
        $required = []; $budget = $first->budgetUnits; $confirmation = $first->confirmation->value; $safety = $first->safety->value;
        foreach ($constraints as $hard) {
            $allowed = ['regions'=>$hard->regions, 'deployments'=>$hard->deployments, 'data_scopes'=>$hard->dataScopes, 'tools'=>$hard->tools, 'capabilities'=>$hard->capabilities];
            foreach ($allowed as $field => $values) {
                $sets[$field] = Values::intersect($sets[$field], $values);
                $denied[$field] = Values::union($denied[$field], $hard->denied->toArray()[$field]);
            }
            $required = Values::union($required, $hard->requiredCapabilities);
            $budget = min($budget, $hard->budgetUnits); $confirmation = max($confirmation, $hard->confirmation->value); $safety = max($safety, $hard->safety->value);
        }
        foreach ($sets as $field => $allowed) { $sets[$field] = Values::subtract($allowed, $denied[$field]); }
        foreach (['regions'=>'REGION_INTERSECTION_EMPTY', 'deployments'=>'DEPLOYMENT_INTERSECTION_EMPTY', 'tools'=>'TOOL_INTERSECTION_EMPTY', 'data_scopes'=>'DATA_SCOPE_INTERSECTION_EMPTY'] as $field => $code) {
            if ($sets[$field] === []) { return $this->conflict($code, $field, $this->sources($input, $overrides, $field), 'No permitted value satisfies all current constraints'); }
        }
        // A required tool capability cannot survive removal of its actual registered tool/schema.
        $capabilities = [];
        foreach ($sets['capabilities'] as $capability) {
            if (str_starts_with($capability, 'tool:') && !in_array(substr($capability, 5), $sets['tools'], true)) { continue; }
            $capabilities[] = $capability;
        }
        if (Values::subtract($required, $capabilities) !== []) {
            return $this->conflict('REQUIRED_CAPABILITY_UNAVAILABLE', 'capabilities', $this->sources($input, $overrides, 'capabilities'), 'A required capability is denied or absent from the allowed intersection');
        }
        if ($budget === 0) { return $this->conflict('BUDGET_EXHAUSTED', 'budget_units', $this->sources($input, $overrides, 'budget_units'), 'An explicit zero cap permits no invocation'); }
        $tone = $definition->defaults->tone; $name = $definition->defaults->displayName; $styles = [];
        foreach ($input->layers as $layer) {
            $style = $layer->style;
            if ($style === null) { continue; }
            foreach (['tone'=>$style->tone?->value, 'display_name'=>$style->displayName] as $field => $value) {
                if ($value === null) { continue; }
                $editable = $field === 'tone' ? $definition->editable->tone : $definition->editable->displayName;
                if (!$editable || ($field === 'display_name' && strlen($value) > $definition->editable->maxDisplayNameBytes)) {
                    return $this->conflict('STYLE_FIELD_NOT_EDITABLE', $field, [
                        ['dependency'=>$definition->dependency, 'value'=>$definition->editable->toArray()],
                        ['dependency'=>$layer->dependency, 'value'=>$value]], 'Style override exceeds the code-declared editable boundary');
                }
                $key = $layer->role->value . ':' . $field;
                if (isset($styles[$key]) && $styles[$key]['value'] !== $value) {
                    return $this->conflict('STYLE_OVERRIDE_AMBIGUOUS', $field, [$styles[$key], ['dependency'=>$layer->dependency, 'value'=>$value]], 'Conflicting style overrides at one declared priority');
                }
                $styles[$key] = ['dependency'=>$layer->dependency, 'value'=>$value];
            }
            $tone = $style->tone ?? $tone; $name = $style->displayName ?? $name;
        }
        $methodology = $profile->methodology; $packRefs = []; $seen = [];
        foreach ($methodology->packs as $pack) {
            if ($pack->compatibility !== $definition->methodologyCompatibility || isset($seen[$pack->dependency->ref->key()])) {
                return $this->conflict('METHODOLOGY_INCOMPATIBLE', 'methodology', [['dependency'=>$definition->dependency, 'value'=>$definition->methodologyCompatibility],
                    ['dependency'=>$pack->dependency, 'value'=>$pack->compatibility]], 'Methodology must be distinct and compatible with the code definition');
            }
            $seen[$pack->dependency->ref->key()] = true; $packRefs[] = $pack->dependency->ref->toArray();
        }
        $composition = $methodology->composition;
        if (($packRefs === [] && $definition->methodologyCompatibility !== 'none')
            || (count($packRefs) > 1 && $composition === null)
            || ($composition !== null && ($composition->compatibility !== $definition->methodologyCompatibility
                || array_map(static fn (\AgenToolsPlatform\Core\Profiles\ResourceRef $r): array => $r->toArray(), $composition->packs) !== $packRefs))) {
            return $this->conflict('METHODOLOGY_COMPOSITION_NOT_APPROVED', 'methodology', $this->sources($input, $overrides, 'methodology'), 'Select one compatible pack or the exact ordered composition from a pinned approval manifest');
        }
        $added = []; $excluded = [];
        foreach ($input->layers as $layer) {
            foreach ($layer->materials->add as $material) { $added[$material->ref->key()] = $material; }
            foreach ($layer->materials->exclude as $material) { $excluded[$material->ref->key()] = true; }
        }
        $materials = [];
        ksort($added, SORT_STRING);
        foreach ($added as $key => $material) {
            if (isset($excluded[$key])) { continue; }
            try { $this->materials->assertCurrent($input->tenant, $material->ref, $boundary); }
            catch (ContractViolation|IntegrityConflict) {
                return $this->conflict('MATERIAL_ACCESS_DENIED', 'materials', [['dependency'=>$material, 'value'=>['boundary'=>$boundary->value]]], 'Current material owner policy refused access');
            }
            $materials[] = $material;
        }
        $selected = null; $preferenceSources = [];
        foreach ($overrides as $override) {
            $preference = $override->preference;
            if ($preference === null) { continue; }
            $preferenceSources[] = ['dependency'=>$override->dependency, 'value'=>$preference->toArray()];
            if (!in_array($preference->deployment, $sets['deployments'], true)) {
                if ($preference->locked) { return $this->conflict('DEPLOYMENT_PREFERENCE_BLOCKED', 'deployment_preference', array_merge($this->sources($input, $overrides, 'deployments'), $preferenceSources), 'A locked preference cannot bypass current deployment restrictions'); }
                continue;
            }
            if ($selected !== null && $selected !== $preference->deployment) {
                return $this->conflict('DEPLOYMENT_PREFERENCE_CONFLICT', 'deployment_preference', $preferenceSources, 'Conflicting deployment preferences require an explicit host choice');
            }
            $selected = $preference->deployment;
        }
        $tools = [];
        foreach ($sets['tools'] as $id) { $tools[] = $input->registry->tools[$id]->toArray(); }
        $effective = ['style'=>['tone'=>$tone->value, 'display_name'=>$name], 'tools'=>$tools, 'methodology'=>$packRefs,
            'materials'=>array_map(static fn (Dependency $d): array => $d->ref->toArray(), $materials), 'selected_deployment'=>$selected];
        $restrictions = $sets; $restrictions['capabilities'] = $capabilities;
        $restrictions += ['forbidden'=>$denied, 'required_capabilities'=>$required, 'confirmation_floor'=>$confirmation,
            'safety_floor'=>$safety, 'budget_units'=>$budget];
        return new \AgenToolsPlatform\Core\Profiles\Composition\CompiledComposition($input,
            new \AgenToolsPlatform\Core\Profiles\CompiledProfile($this->json->canonicalize($effective), $this->json->canonicalize($restrictions), $this->json), $materials);
    }

    /**
     * @param list<OperationalOverrides> $overrides
     * @return list<array{dependency: Dependency, value: mixed}>
     */
    private function sources(CompilationInput $input, array $overrides, string $field): array
    {
        $sources = [];
        foreach (array_merge($input->layers, $overrides) as $source) {
            $hard = $source->hard->toArray();
            $value = ['allowed'=>$hard[$field] ?? null, 'forbidden'=>$hard['forbidden'], 'required'=>$source->hard->requiredCapabilities];
            if ($field === 'capabilities') { $value['tools'] = $source->hard->tools; }
            if ($field === 'layers') { $value = ['role'=>$source->role->name]; }
            if ($field === 'dependencies') { $value = $source->dependency->toArray(); }
            if ($field === 'methodology' && $source instanceof PolicyLayer) {
                $value = ['compatibility'=>$source->methodologyCompatibility, 'selection'=>$source->methodology->toArray()];
                foreach ($source->methodology->packs as $pack) { $sources[] = ['dependency'=>$pack->dependency, 'value'=>$pack->toArray()]; }
                if ($source->methodology->composition !== null) {
                    $sources[] = ['dependency'=>$source->methodology->composition->dependency,
                        'value'=>array_map(static fn (\AgenToolsPlatform\Core\Profiles\ResourceRef $r): array => $r->toArray(), $source->methodology->composition->packs)];
                }
            }
            $sources[] = ['dependency'=>$source->dependency, 'value'=>$value];
        }
        if (in_array($field, ['tools','capabilities'], true)) { $sources[] = ['dependency'=>$input->registry->dependency, 'value'=>array_keys($input->registry->tools)]; }
        if ($field === 'deployments') { foreach ($input->deployments as $d) { $sources[] = ['dependency'=>$d, 'value'=>$d->ref->toArray()]; } }
        return $sources;
    }
    /**
     * @param list<array{dependency: Dependency, value: mixed}> $sources
     */
    private function conflict(string $code, string $field, array $sources, string $reason): ProfileConflict
    {
        return new \AgenToolsPlatform\Core\Profiles\Composition\ProfileConflict($code, $field, $sources, $reason, $this->json);
    }
}
