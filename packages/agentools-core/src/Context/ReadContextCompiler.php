<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, Ref, RunId, TurnId, UtcInstant};
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Two-phase pure compiler: trusted ports supply permissions, deployment and API adapters. */
final readonly class ReadContextCompiler
{
    public function __construct(private ContextAccess $access, private CanonicalHasher $hasher,
        private ?DeploymentAccess $deployments = null, private ?ToolAccess $tools = null) {}

    /** @param array<array-key, ContextBlock> $blocks */
    public function buildPlan(ExecutionContext $context, TurnId $turn, RunId $run, BundleRef $bundle,
        array $blocks, UtcInstant $now, SnapshotPins $pins = new \AgenToolsPlatform\Core\Context\SnapshotPins()): ContextPlan
    {
        foreach ([$turn, $run, $bundle->ref, $pins->routingDecision] as $link) {
            if ($link !== null) { $context->session->tenant->assertSame($link->tenant); }
        }
        foreach ($pins->dependencies as $pin) { $context->session->tenant->assertSame($pin['ref']->tenant); }
        if (!array_is_list($blocks)) { throw new \InvalidArgumentException('Context blocks must be a list'); }
        $accepted=[]; $excluded=[]; $seen=[]; $hasPolicy=false; $hasInput=false;
        foreach ($blocks as $block) {
            $block=$this->trustedBlock($block);
            $reason=$block->ref->tenant->value !== $context->session->tenant->value ? 'cross_tenant'
                : ($block->purpose !== $context->purpose ? 'purpose_mismatch'
                : ($this->expired($block->expiresAt,$now) ? 'expired'
                : (!$this->access->permits($context,$block) ? 'owner_denied' : null)));
            if (isset($seen[$block->ref->value])) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
            $seen[$block->ref->value]=true;
            if ($reason === null && in_array($block->kind,['tools','tool_result'],true)
                && ($block->provenance->toolId === null || $this->tools === null
                    || !$this->tools->permits($context,$block->provenance->toolId))) { $reason='tool_not_allowed'; }
            if ($reason !== null) {
                if ($block->mandatory) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied); }
                // No foreign-tenant identifiers enter this tenant's snapshot.
                $excluded[]=$reason === 'cross_tenant' ? ['id'=>'foreign-slice','reason'=>$reason] : $this->exclusion($block,$reason);
                continue;
            }
            $accepted[]=$block;
            $hasPolicy=$hasPolicy || $block->kind === 'policy'; $hasInput=$hasInput || $block->kind === 'input';
        }
        if (!$hasPolicy || !$hasInput) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict); }
        $order=['policy','profile','skill','tools','goal','working_state','promise','subject','evidence','tool_result','summary','recent','example','input'];
        usort($accepted,static function(ContextBlock $a,ContextBlock $b) use($order): int {
            $left=0; $right=0;
            foreach ($order as $i=>$kind) { if ($kind === $a->kind) { $left=$i; } if ($kind === $b->kind) { $right=$i; } }
            return $left <=> $right ?: strcmp($a->ref->value,$b->ref->value);
        });
        return new \AgenToolsPlatform\Core\Context\ContextPlan($context,$turn,$run,$bundle,$accepted,$excluded,$pins);
    }

    /**
     * Select only within host constraints before renderer/tokenizer binding.
     * @param list<ContextTarget> $targets
     */
    public function selectDeployment(ContextPlan $plan, array $targets, UtcInstant $now): ContextTarget
    {
        if ($this->deployments === null) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable); }
        foreach ($targets as $target) {
            if ($target->deployment->tenant->value === $plan->run->tenant->value && $this->deployments->permits($plan,$target,$now)) { return $target; }
        }
        throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable);
    }

    /** Legacy caller may supply a trusted already-selected target; new host composition supplies DeploymentAccess. */
    public function finalize(ContextPlan $plan, ContextTarget $target, TokenCounter $counter, UtcInstant $now,
        ?Renderer $renderer = null): CompiledContext
    {
        $plan->run->tenant->assertSame($target->deployment->tenant);
        if ($this->deployments !== null && !$this->deployments->permits($plan,$target,$now)) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied);
        }
        $renderer ??= new \AgenToolsPlatform\Core\Context\SectionRenderer($target->deployment);
        $target->deployment->assertSame($counter->deployment()); $target->deployment->assertSame($renderer->deployment());
        $version=$counter->version(); $confidence=$counter->confidence(); $rendererVersion=$renderer->version(); $layout=$renderer->layout();
        if (!in_array($confidence,['exact','conservative'],true) || $version === '' || $rendererVersion === '' || $layout === '') {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable);
        }
        $checked=$this->buildPlan($plan->context,$plan->turn,$plan->run,$plan->bundle,$plan->blocks,$now,$plan->pins);
        $excluded=array_merge($plan->exclusions,$checked->exclusions); $selected=[]; $dedup=[];
        // Mandatory copies win even when an optional duplicate sorts earlier.
        foreach ($checked->blocks as $block) { if ($block->mandatory) { $dedup[$this->dedupKey($block)]=$block->ref->value; } }
        foreach ($checked->blocks as $block) {
            $key=$this->dedupKey($block);
            if (!$block->mandatory && !$block->provenance->relevant) { $excluded[]=$this->exclusion($block,'irrelevant'); continue; }
            if (!$block->mandatory && isset($dedup[$key])) {
                $excluded[]=$this->exclusion($block,'duplicate')+['retained_id'=>$dedup[$key]]; continue;
            }
            $dedup[$key]=$block->ref->value; $selected[]=$block;
        }
        while (true) {
            $rendered=$renderer->render($selected);
            $used=$this->count($counter,$rendered,$target,$version,$confidence);
            if ($used <= $target->inputBudget()) { break; }
            $candidate=null;
            foreach ($selected as $index=>$block) {
                if (!$block->mandatory && ($candidate === null || $block->priority < $selected[$candidate]->priority
                    || ($block->priority === $selected[$candidate]->priority && $this->pruneRank($block) < $this->pruneRank($selected[$candidate])))) { $candidate=$index; }
            }
            if ($candidate === null) {
                throw new \AgenToolsPlatform\Core\Context\BudgetOverflow(['code'=>'CONTEXT_MANDATORY_OVERFLOW',
                    'available_tokens'=>$target->inputBudget(),'required_tokens'=>$used,'count_confidence'=>$confidence,
                    'tokenizer_version'=>$version,'renderer_version'=>$rendererVersion,
                    'mandatory_blocks'=>array_map(static fn(ContextBlock $b): array=>$b->manifest(),$selected),'exclusions'=>$excluded]);
            }
            $excluded[]=$this->exclusion($selected[$candidate],'input_budget');
            $remaining=[];
            foreach ($selected as $index=>$block) { if ($index !== $candidate) { $remaining[]=$block; } }
            $selected=$remaining;
        }
        // Attribute the exact API envelope and delimiters, not just modelView text.
        $prefix=[]; $previous=$this->count($counter,$renderer->render([]),$target,$version,$confidence); $overhead=$previous;
        $sections=[]; $classification='public'; $sourceMessages=[];
        foreach ($selected as $block) {
            $prefix[]=$block; $total=$this->count($counter,$renderer->render($prefix),$target,$version,$confidence);
            if ($total < $previous) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable); }
            $sections[]=$block->manifest()+['available_tokens'=>max(0,$target->inputBudget()-$previous),'used_tokens'=>$total-$previous]; $previous=$total;
            if ($block->classification === 'restricted') { $classification='restricted'; }
            elseif ($classification === 'public' && $block->classification === 'private') { $classification='private'; }
            foreach ($block->provenance->messageIds as $message) { $sourceMessages[$message->value]=$message->toArray(); }
        }
        if ($previous !== $used || $renderer->render($selected) !== $rendered || $renderer->version() !== $rendererVersion || $renderer->layout() !== $layout) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable);
        }
        // A port invoked during rendering/counting cannot silently revoke a pinned source.
        foreach ($selected as $block) {
            if (!$this->access->permits($plan->context,$block)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext); }
            if (in_array($block->kind,['tools','tool_result'],true) && ($block->provenance->toolId === null || $this->tools === null
                || !$this->tools->permits($plan->context,$block->provenance->toolId))) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext); }
        }
        if ($this->deployments !== null && !$this->deployments->permits($plan,$target,$now)) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext);
        }
        $target->deployment->assertSame($renderer->deployment()); $target->deployment->assertSame($counter->deployment());
        if ($counter->version() !== $version || $counter->confidence() !== $confidence) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable);
        }
        ksort($sourceMessages);
        usort($excluded,static fn(array $a,array $b): int=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));
        $manifest=['compiler_version'=>'context-compiler/v2','renderer_version'=>$rendererVersion,'renderer_layout'=>$layout,
            'tokenizer_version'=>$version,'count_confidence'=>$confidence,
            'counting_contract'=>$confidence === 'exact' ? 'selected-deployment exact count' : 'host documented conservative bound identified by tokenizer_version',
            'tenant_id'=>$plan->run->tenant->value,'session_id'=>$plan->context->session->value,'turn_id'=>$plan->turn->value,'run_id'=>$plan->run->value,
            'bundle'=>$plan->bundle->toArray(),'policy_revision'=>$plan->context->identity->policyRevision,
            'scope_hash'=>$plan->context->scopeHash($this->hasher)->value,'deployment_ref'=>$target->deployment->value,
            'deployment'=>$target->deployment->toArray(),
            'model_version'=>$target->modelVersion,'capability_snapshot_ref'=>$target->capabilitySnapshot?->toArray(),
            'capability_snapshot_hash'=>$target->capabilityHash?->value,
            'context_window'=>$target->window,'output_reserve'=>$target->outputReserve,'requested_max_output'=>$target->requestedMaxOutput,
            'safety_margin'=>$target->safetyMargin,'available_tokens'=>$target->inputBudget(),'used_tokens'=>$used,'framing_tokens'=>$overhead,
            'sections'=>$sections,'source_message_ids'=>array_values($sourceMessages),'exclusions'=>$excluded,'exclusion_count'=>count($excluded),
            'classification'=>$classification,'retention'=>$plan->pins->retention,'pins'=>$plan->pins->toArray()];
        $hash=$this->hasher->hash($plan->run->tenant,'context-snapshot',['manifest'=>$manifest,'rendered'=>$rendered]);
        $ref=new \AgenToolsPlatform\Core\Common\Ref($plan->run->tenant,'snapshot:'.substr($hash->value,8));
        $manifest['snapshot_id']=$ref->value; $manifest['immutable_content_hash']=$hash->value;
        return new \AgenToolsPlatform\Core\Context\CompiledContext(new \AgenToolsPlatform\Core\Context\ContextSnapshot(
            $ref,1,$hash,$plan->context->scopeHash($this->hasher)),$rendered,json_encode($manifest,JSON_THROW_ON_ERROR));
    }

    /** Explicit boundary before releasing cached compiled bytes; the old manifest is never rewritten. */
    public function authorizeReuse(ContextPlan $plan, CompiledContext $compiled, SnapshotAccess $snapshots, UtcInstant $now): void
    {
        $compiled->assertIntegrity($plan->context,$this->hasher);
        $manifest=json_decode($compiled->manifestJson,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['run_id'] ?? null) !== $plan->run->value
            || ($manifest['turn_id'] ?? null) !== $plan->turn->value
            || ($manifest['bundle'] ?? null) !== $plan->bundle->toArray()
            || ($manifest['pins'] ?? null) !== $plan->pins->toArray()
            || !is_array($manifest['sections'] ?? null) || !array_is_list($manifest['sections'])) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict);
        }
        $blocks=[];
        foreach ($plan->blocks as $block) { $blocks[$block->ref->value]=$block; }
        foreach ($manifest['sections'] as $section) {
            if (!is_array($section) || !is_string($section['id'] ?? null)) {
                throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict);
            }
            $block=$blocks[$section['id']] ?? null;
            if ($block !== null) {
                foreach ($block->manifest() as $key=>$value) {
                    if (($section[$key] ?? null) !== $value) {
                        throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict);
                    }
                }
            }
            if ($block === null || ($section['content_hash'] ?? null) !== $block->hash->value
                || $this->expired($block->expiresAt,$now) || !$this->access->permits($plan->context,$block)
                || (in_array($block->kind,['tools','tool_result'],true) && ($block->provenance->toolId === null
                    || $this->tools === null || !$this->tools->permits($plan->context,$block->provenance->toolId)))) {
                throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext);
            }
        }
        if (!$snapshots->permits($plan->context,$compiled->snapshot)) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext);
        }
    }

    public function captureDiagnostics(ExecutionContext $context, CompiledContext $compiled, Diagnostics $diagnostics,
        UtcInstant $now, UtcInstant $expiresAt): DiagnosticRef
    {
        $compiled->snapshot->assertScope($context,$this->hasher);
        if ($this->expired($expiresAt,$now)) { throw new \InvalidArgumentException('Diagnostic TTL must be in the future'); }
        $receipt=$diagnostics->capture($context,$compiled,$expiresAt);
        $context->session->tenant->assertSame($receipt->ref->tenant); $compiled->snapshot->contentHash->assertSame($receipt->snapshotHash);
        if ($receipt->expiresAt->value !== $expiresAt->value) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
        return $receipt;
    }

    private function trustedBlock(mixed $value): ContextBlock
    {
        if (!$value instanceof ContextBlock) { throw new \InvalidArgumentException('Only trusted ContextBlock values are accepted'); }
        return $value;
    }
    private function count(TokenCounter $counter,string $rendered,ContextTarget $target,string $version,string $confidence): int
    {
        $count=$counter->count($rendered); $target->deployment->assertSame($counter->deployment());
        if ($count < 0 || $count > 9007199254740991 || $counter->version() !== $version || $counter->confidence() !== $confidence) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable);
        }
        return $count;
    }
    private function dedupKey(ContextBlock $block): string
    {
        return $this->hasher->hash($block->ref->tenant,'context-dedup',[$block->kind,$block->trust,$block->classification,
            $block->purpose,$block->owner->value,$block->hash->value])->value;
    }
    private function pruneRank(ContextBlock $block): int
    {
        return match($block->kind) { 'example'=>0,'summary'=>1,'profile'=>2,'subject'=>3,'recent'=>5,'evidence'=>6,'goal'=>7,default=>4 };
    }
    /** @return array<string, mixed> */
    private function exclusion(ContextBlock $block,string $reason): array { return ['id'=>$block->ref->value,'reason'=>$reason,'block'=>$block->manifest()]; }
    private function expired(UtcInstant $expires,UtcInstant $now): bool
    {
        return strcmp($this->timeKey($expires),$this->timeKey($now)) <= 0;
    }
    private function timeKey(UtcInstant $time): string
    {
        return substr($time->value,0,19).'.'.substr((strlen($time->value)>20 ? substr($time->value,20,-1) : '').'000000',0,6);
    }
}
