<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\PayloadHash;

final readonly class ToolPin
{
    public function __construct(public string $name,public string $definitionId,public string $version,public PayloadHash $definitionHash,public PayloadHash $schemaHash)
    { if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D',$name)!==1 || preg_match('/^[a-z][a-z0-9._-]{0,127}$/D',$definitionId)!==1 || $version==='') { throw new \InvalidArgumentException('Qualified tool tuple required'); } }
    /** @return array<string,string> */
    public function toArray(): array { return ['name'=>$this->name,'definition_id'=>$this->definitionId,'version'=>$this->version,'definition_hash'=>$this->definitionHash->value,'schema_hash'=>$this->schemaHash->value]; }
}
