<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\{PayloadHash, TenantId};

/** Synthetic authoritative owner facts, not client caches or durable persistence. */
final class ExternalState
{
    /** @var array<string,Record> */
    private array $rows=[];
    public function get(TenantId $tenant,string $table,string $id): ?Record { return $this->rows[$this->key($tenant,$table,$id)]??null; }
    public function put(TenantId $tenant,string $table,string $id,PayloadHash $hash,mixed $value): Record
    {
        $key=$this->key($tenant,$table,$id);$saved=$this->rows[$key]??null;
        if($saved!==null){$saved->hash->assertSame($hash);return $saved;}
        return $this->rows[$key]=new Record($hash,$value);
    }
    private function key(TenantId $tenant,string $table,string $id): string { return json_encode([$tenant->value,$table,$id],JSON_THROW_ON_ERROR); }
    public function __debugInfo(): array { return ['synthetic_owner_facts'=>'[redacted]']; }
}
