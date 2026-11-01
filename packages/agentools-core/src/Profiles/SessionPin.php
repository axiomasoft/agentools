<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

use AgenToolsPlatform\Core\Common\{InstanceId, SessionId};

/** Revision 1 is initial assignment; every later revision has an immutable migration audit row. */
final readonly class SessionPin
{
    public function __construct(public SessionId $session, public InstanceId $instance, public int $revision, public BundleRef $bundle)
    {
        $session->tenant->assertSame($instance->tenant);
        $session->tenant->assertSame($bundle->ref->tenant);
        if ($revision < 1 || $revision > 9007199254740991) { throw new \InvalidArgumentException('Positive session revision required'); }
    }
}
