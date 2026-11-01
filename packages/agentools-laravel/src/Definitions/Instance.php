<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

use AgenToolsPlatform\Core\Common\InstanceId;
use AgenToolsPlatform\Core\Profiles\BundleRef;

/** Assignment and draft data only. Definition identity remains code-owned. */
final readonly class Instance
{
    public function __construct(public InstanceId $id,public int $revision,public Reference $definition,
        public BundleRef $bundle,public DraftSettings $draft)
    {
        $id->tenant->assertSame($bundle->ref->tenant);$definition->owner->assertVisible($id->tenant);
        if ($revision<1 || $revision>9007199254740991) { throw new \InvalidArgumentException('Instance revision required'); }
    }
}
