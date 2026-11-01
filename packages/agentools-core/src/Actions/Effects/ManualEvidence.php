<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Effects;

use AgenToolsPlatform\Core\Common\Ref;

final readonly class ManualEvidence
{
    public function __construct(public Ref $operator, public Ref $evidence, public string $basis,
        public LookupResult $outcome)
    {
        $operator->tenant->assertSame($evidence->tenant);
        $operator->tenant->assertSame($outcome->operation->tenant);
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D',$basis) !== 1
            || !in_array($outcome->status, [LookupStatus::Succeeded, LookupStatus::Failed], true)) {
            throw new \InvalidArgumentException('Manual resolution needs a known outcome and evidence basis');
        }
    }
}
