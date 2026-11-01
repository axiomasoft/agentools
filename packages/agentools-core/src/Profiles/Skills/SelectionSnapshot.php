<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash, RunId};
use AgenToolsPlatform\Core\Profiles\{DefinitionRef, ResourceRef};

/** One selected version. Semantic manifest is reproducible across otherwise identical Runs. */
final readonly class SelectionSnapshot implements \JsonSerializable
{
    public PayloadHash $hash;
    public function __construct(private RunId $run, public PayloadHash $routingHash, public ResourceRef $skill,
        public QualificationTuple $tuple, public DefinitionRef $counter, CanonicalJson $json)
    {
        $skill->owner->assertVisible($run->tenant);
        $this->hash=new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.hash('sha256',$json->canonicalize($this->manifest())));
    }
    public function assertRun(RunId $run): void { $this->run->assertSame($run); }
    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return ['format'=>'skill-selection/v1','routing_hash'=>$this->routingHash->value,'selected_skill'=>$this->skill->toArray(),
            'qualification_tuple_hash'=>$this->tuple->hash->value,'prompt_hash'=>$this->tuple->promptsHash->value,
            'tool_schema_hash'=>$this->tuple->toolsHash->value,'counter'=>$this->counter->toArray()];
    }
    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array { return $this->manifest()+['selection_hash'=>$this->hash->value]; }
}
