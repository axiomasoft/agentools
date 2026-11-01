<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

use AgenToolsPlatform\Core\Common\InstanceId;
use AgenToolsPlatform\Core\Profiles\BundleRef;

/** Runtime binding deliberately has no editable draft fields. */
final readonly class SessionPin
{
    public function __construct(public InstanceId $id,public Reference $definition,public BundleRef $bundle)
    {
        $id->tenant->assertSame($bundle->ref->tenant);
        $definition->owner->assertVisible($id->tenant);
    }
}
