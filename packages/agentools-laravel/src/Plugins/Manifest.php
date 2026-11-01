<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Plugins;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;
use AgenToolsPlatform\Laravel\Definitions\{OwnerScope, Reference};

final readonly class Manifest
{
    public Reference $ref;
    /**
     * @param array<array-key,Phase> $phases
     * @param array<array-key,string> $purposes
     */
    public function __construct(OwnerScope $owner,string $id,string $version,public array $phases,public array $purposes,
        public FailurePolicy $failure,public int $tokenBudget,public PayloadHash $codeHash,public string $sourceRef,CanonicalJson $json)
    {
        if (!array_is_list($phases) || $phases===[] || count(array_unique(array_map(static fn(Phase $p):string=>$p->value,$phases)))!==count($phases)
            || !array_is_list($purposes) || $purposes===[] || $tokenBudget<0 || $tokenBudget>1000000 || $sourceRef==='' || strlen($sourceRef)>256) {
            throw new \InvalidArgumentException('Code manifest phases, purpose and bounded budget required');
        }
        foreach ($purposes as $purpose) { if (!self::validPurpose($purpose)) { throw new \InvalidArgumentException('Purpose required'); } }
        $hash=new PayloadHash('sha-256:'.hash('sha256',$json->canonicalize(['format'=>'code-plugin/v1','owner'=>$owner->toArray(),
            'id'=>$id,'version'=>$version,'phases'=>array_map(static fn(Phase $p):string=>$p->value,$phases),'purposes'=>$purposes,
            'failure'=>$failure->value,'token_budget'=>$tokenBudget,'code_hash'=>$codeHash->value,'source_ref'=>$sourceRef])));
        $this->ref=new Reference($owner,new DefinitionRef($id,$version,$hash));
    }
    private static function validPurpose(mixed $purpose): bool { return is_string($purpose) && preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D',$purpose)===1; }
}
