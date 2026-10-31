<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Common\Ref;

/** Observed dated projection; unresolved effects retain their safe next step. */
final readonly class ReconcileReceipt
{
    public function __construct(public Ref $commandRef, public ActionView $view)
    {
        $commandRef->tenant->assertSame($view->receipt->actionId->tenant);
    }
}
