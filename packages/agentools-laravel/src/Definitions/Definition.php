<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

use AgenToolsPlatform\Core\Common\{CanonicalJson, ContractViolation, ErrorCode, PayloadHash};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;

/** Installed from trusted code only. Published bytes cannot be edited through an instance. */
final readonly class Definition
{
    public Reference $ref;
    /** @var list<Reference> */
    public array $plugins;
    /**
     * @param array<array-key,string> $editable
     * @param array<array-key,Reference> $plugins
     */
    public function __construct(OwnerScope $owner,string $id,string $version,public DefaultSettings $defaults,
        public HardConstraints $restrictions,public array $editable,array $plugins,
        public string $createdByRef,public string $validationRef,public string $evaluationRef,CanonicalJson $json)
    {
        if (!array_is_list($editable) || count(array_unique($editable))!==count($editable)
            || array_diff($editable,['tone','display_name'])!==[] || !array_is_list($plugins)) { throw new \InvalidArgumentException('Declared editable fields and pinned plugins required'); }
        foreach ([$createdByRef,$validationRef,$evaluationRef] as $ref) {
            if ($ref==='' || strlen($ref)>256) { throw new \InvalidArgumentException('Provenance reference required'); }
        }
        $seen=[];foreach ($plugins as $plugin) { if (isset($seen[$plugin->key()])) { throw new ContractViolation(ErrorCode::IntegrityConflict); } $seen[$plugin->key()]=true; }
        $hash=new PayloadHash('sha-256:'.hash('sha256',$json->canonicalize(['format'=>'assistant-code-definition/v1',
            'owner'=>$owner->toArray(),'id'=>$id,'version'=>$version,'defaults'=>$defaults->toArray(),
            'restrictions'=>$restrictions->toArray(),'editable'=>$editable,'plugins'=>array_map(static fn (Reference $ref):array=>$ref->toArray(),$plugins),
            'created_by_ref'=>$createdByRef,'validation_ref'=>$validationRef,'evaluation_ref'=>$evaluationRef])));
        $this->plugins=$plugins;
        $this->ref=new Reference($owner,new DefinitionRef($id,$version,$hash));
    }
    public function preview(DraftSettings $draft): DefaultSettings
    {
        if (($draft->tone!==null && !in_array('tone',$this->editable,true))
            || ($draft->displayName!==null && !in_array('display_name',$this->editable,true))) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        return new DefaultSettings($draft->tone??$this->defaults->tone,$draft->displayName??$this->defaults->displayName);
    }
}
