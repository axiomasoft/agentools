<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};

/** Refs and bounded operational state, not another conversation archive. */
final readonly class WorkingState
{
    public function __construct(public WorkingProposal $value,public int $version,public Ref $acceptance,
        public UtcInstant $expiresAt,public string $retention,public int $schemaVersion=1)
    {
        Checks::positive($version);$value->branch->tenant->assertSame($acceptance->tenant);
        if ($schemaVersion!==1 || $retention==='') { throw new \InvalidArgumentException('Known working-state schema/retention required'); }
    }
    /** @return array<string,mixed> */
    public function toArray(): array { return $this->value->toArray()+['schema_version'=>$this->schemaVersion,'version'=>$this->version,'acceptance_ref'=>$this->acceptance->toArray(),'expires_at'=>$this->expiresAt->value,'retention'=>$this->retention]; }
}
