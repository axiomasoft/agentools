<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash};
use AgenToolsPlatform\Core\Profiles\{DefinitionRef, Dependency, OwnerScope, Provenance, ResourceKind};
use AgenToolsPlatform\Core\Profiles\Composition\{Document, SafetyFloor};

/** Short immutable metadata. No content, history, schemas, credentials or executable names. */
final readonly class SkillManifest
{
    public Dependency $dependency;
    /**
     * @var list<string>
     */ public array $triggers;
    /**
     * @var list<string>
     */ public array $requiredContext;
    /**
     * @var list<DefinitionRef>
     */ public array $tools;
    /**
     * @var list<FragmentRef>
     */ public array $fragments;
    /**
     * @var list<string>
     */ public array $rubrics;
    /**
     * @var list<string>
     */ public array $evaluations;

    /**
     * @param array<array-key, mixed> $triggers
     * @param array<array-key, mixed> $requiredContext
     * @param list<DefinitionRef> $tools
     * @param list<FragmentRef> $fragments
     * @param array<array-key, mixed> $rubrics
     * @param array<array-key, mixed> $evaluations
     */
    public function __construct(OwnerScope $owner, string $id, string $version, public string $purpose, array $triggers,
        array $requiredContext, array $tools, array $fragments, array $rubrics, public int $tokenBudget,
        public SafetyFloor $safety, public Compatibility $compatibility, array $evaluations, Provenance $provenance,
        CanonicalJson $json, ?PayloadHash $expectedHash = null)
    {
        Bounds::names([$purpose]);
        $this->triggers = Bounds::names($triggers); $this->requiredContext = Bounds::names($requiredContext);
        if ($this->triggers === [] || $tokenBudget < 1 || $tokenBudget > 16384 || count($tools) > 16
            || $fragments === [] || count($fragments) > 4) { throw new \InvalidArgumentException('Short explicit skill manifest bounds required'); }
        $seen = [];
        foreach ($tools as $tool) { if (isset($seen[$tool->id])) { throw new \InvalidArgumentException('One pinned version per tool required'); } $seen[$tool->id]=true; }
        usort($tools, static fn (DefinitionRef $a, DefinitionRef $b): int => strcmp($a->id,$b->id));
        $seen = [];
        foreach ($fragments as $fragment) { if (isset($seen[$fragment->key()])) { throw new \InvalidArgumentException('Distinct pinned fragments required'); } $seen[$fragment->key()]=true; }
        usort($fragments, static fn (FragmentRef $a, FragmentRef $b): int => strcmp($a->key(),$b->key()));
        $this->tools=$tools; $this->fragments=$fragments; $this->rubrics=Bounds::refs($rubrics); $this->evaluations=Bounds::refs($evaluations);
        $this->dependency = Document::dependency($owner,ResourceKind::Skill,$id,$version,$provenance,$this->content(),$json,$expectedHash);
        if (strlen($json->canonicalize($this->toArray())) > 8192) { throw new \InvalidArgumentException('Skill metadata exceeds short manifest byte bound'); }
    }
    /**
     * @return array<string, mixed>
     */
    public function content(): array
    {
        return ['format'=>'skill-manifest/v1','purpose'=>$this->purpose,'triggers'=>$this->triggers,'required_context'=>$this->requiredContext,
            'allowed_tools'=>array_map(static fn (DefinitionRef $t): array => $t->toArray(),$this->tools),
            'prompt_fragments'=>array_map(static fn (FragmentRef $f): array => $f->toArray(),$this->fragments),
            'rubric_refs'=>$this->rubrics,'token_budget'=>$this->tokenBudget,'safety_class'=>$this->safety->value,
            'compatibility'=>$this->compatibility->toArray(),'evaluation_refs'=>$this->evaluations];
    }
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array { return ['resource'=>$this->dependency->ref->toArray(),'provenance'=>$this->dependency->provenance->toArray()]+$this->content(); }

    /** Cached objects must retain their actual content digest, not just an old reference. */
    public function assertIntegrity(CanonicalJson $json): void
    {
        $ref=$this->dependency->ref;
        if ($ref->kind !== ResourceKind::Skill) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Skill resource kind mismatch'); }
        $actual=Document::dependency($ref->owner,ResourceKind::Skill,$ref->version->id,$ref->version->version,
            $this->dependency->provenance,$this->content(),$json);
        $ref->version->hash->assertSame($actual->ref->version->hash);
    }
}
