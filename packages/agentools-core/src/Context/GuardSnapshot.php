<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,ContractViolation,ErrorCode,PayloadHash,Ref};
use AgenToolsPlatform\Core\Security\{ExecutionContext,SourcePin};

/** Adapter over the existing compiler/trust model, never a second source of grants. */
final readonly class GuardSnapshot implements \AgenToolsPlatform\Core\Security\SnapshotEvidence
{
    /** @var list<SourcePin> */ private array $pins;
    private string $renderer;
    private bool $restricted;
    private PayloadHash $renderHash;
    public function __construct(private ContextPlan $plan,private CompiledContext $compiled,private ContextTarget $target,CanonicalHasher $hasher)
    {
        $plan->run->tenant->assertSame($target->deployment->tenant);$compiled->assertIntegrity($plan->context,$hasher);$m=json_decode($compiled->manifestJson,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($m) || !is_array($m['sections']??null) || !is_string($m['renderer_version']??null)
            || ($m['deployment_ref']??null)!==$target->deployment->value || ($m['run_id']??null)!==$plan->run->value
            || ($m['turn_id']??null)!==$plan->turn->value || ($m['bundle']??null)!==$plan->bundle->toArray()
            || ($m['pins']??null)!==$plan->pins->toArray()) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
        $byId=[];foreach ($plan->blocks as $block) { $byId[$block->ref->value]=$block; }$selected=[];$pins=[];$restricted=false;
        foreach ($m['sections'] as $section) {
            if (!is_array($section) || !is_string($section['id']??null) || isset($selected[$section['id']]) || !isset($byId[$section['id']])) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
            $block=$byId[$section['id']];$metadata=$section;unset($metadata['available_tokens'],$metadata['used_tokens']);
            if ($metadata!==$block->manifest()) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
            $selected[$section['id']]=true;$pins[]=new \AgenToolsPlatform\Core\Security\SourcePin($block->source,$block->contentRef,$block->sourceVersion,$block->hash,$block->expiresAt,$block->ref,$block->provenance->messageIds);
            $restricted=$restricted || $block->classificationTag===Classification::Restricted;
        }
        foreach ($plan->blocks as $block) { if ($block->mandatory && !isset($selected[$block->ref->value])) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); } }
        if (!isset($m['classification']) || !is_string($m['classification'])) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
        $pins[]=new \AgenToolsPlatform\Core\Security\SourcePin($plan->bundle->ref,$plan->bundle->ref,(string)$plan->bundle->version,$plan->bundle->hash,null);
        if ($target->capabilitySnapshot!==null && $target->capabilityHash!==null) { $pins[]=new \AgenToolsPlatform\Core\Security\SourcePin($target->capabilitySnapshot,$target->capabilitySnapshot,$target->capabilitySnapshot->value,$target->capabilityHash,null); }
        foreach ($plan->pins->dependencies as $pin) { $pins[]=new \AgenToolsPlatform\Core\Security\SourcePin($pin['ref'],$pin['ref'],$pin['version'],$pin['hash'],null); }
        if (($m['classification']==='restricted')!==$restricted) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
        $this->pins=$pins;$this->renderer=$m['renderer_version'];$this->restricted=$restricted;
        $this->renderHash=$hasher->hash($plan->run->tenant,'context-rendered',$compiled->rendered);
    }
    public function ref(): Ref { return $this->compiled->snapshot->ref; }
    public function revision(): int { return $this->compiled->snapshot->revision; }
    public function hash(): PayloadHash { return $this->compiled->snapshot->contentHash; }
    public function deployment(): Ref { return $this->target->deployment; }
    public function rendererVersion(): string { return $this->renderer; }
    public function renderedHash(): PayloadHash { return $this->renderHash; }
    public function sensitive(): bool { return $this->restricted; }
    public function sources(): array { return $this->pins; }
    public function assertBound(ExecutionContext $context,CanonicalHasher $hasher): void { $this->compiled->assertIntegrity($context,$hasher); }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return ['snapshot_ref'=>$this->ref()->value,'snapshot_hash'=>$this->hash()->value,'source_count'=>count($this->pins)]; }
}
