<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

use AgenToolsPlatform\Core\Common\{PayloadHash, TenantId};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;

final readonly class Reference
{
    public function __construct(public OwnerScope $owner, public DefinitionRef $version) {}
    public function key(): string { return $this->owner->key().'/'.$this->version->id.'@'.$this->version->version; }
    /** @return array{owner:array{kind:string,tenant_id?:string},id:string,version:string,hash:string} */
    public function toArray(): array { return ['owner'=>$this->owner->toArray(),...$this->version->toArray()]; }
    public static function fromJson(string $json): self
    {
        $d=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($d) || count($d)!==4 || !is_array($d['owner']??null) || !is_string($d['id']??null)
            || !is_string($d['version']??null) || !is_string($d['hash']??null)) { throw new \InvalidArgumentException('Definition reference required'); }
        $owner=$d['owner'];
        if ($owner===['kind'=>'platform']) { $scope=OwnerScope::platform(); }
        elseif (count($owner)===2 && ($owner['kind']??null)==='tenant' && is_string($owner['tenant_id']??null)) { $scope=OwnerScope::tenant(new TenantId($owner['tenant_id'])); }
        else { throw new \InvalidArgumentException('Explicit owner scope required'); }
        return new self($scope,new DefinitionRef($d['id'],$d['version'],new PayloadHash($d['hash'])));
    }
}
