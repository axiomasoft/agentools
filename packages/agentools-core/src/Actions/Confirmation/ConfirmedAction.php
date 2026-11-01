<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Confirmation;

use AgenToolsPlatform\Core\Common\{ActionId, PayloadHash, Ref};

/** Confirmation receipt only. The owner must load durable evidence and reauthorize its write. */
final readonly class ConfirmedAction
{
    public function __construct(public ActionId $action, public Ref $proposal, public PayloadHash $bindingHash,
        public int $version, public int $epoch)
    {
        $action->tenant->assertSame($proposal->tenant);
        if ($version<1 || $epoch<0 || max($version,$epoch)>9007199254740991) {
            throw new \InvalidArgumentException('A confirmation receipt requires bounded version and epoch');
        }
    }
}
