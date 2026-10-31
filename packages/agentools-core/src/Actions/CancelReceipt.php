<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Common\Ref;

/** Acknowledges the persisted cancellation request, never an effect rollback. */
final readonly class CancelReceipt
{
    public function __construct(public Ref $commandRef, public ActionView $view)
    {
        $commandRef->tenant->assertSame($view->receipt->actionId->tenant);
    }
}
