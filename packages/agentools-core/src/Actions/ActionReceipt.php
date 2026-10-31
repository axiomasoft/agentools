<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

/** Durable acceptance from the lifecycle adapter, not a promise of completion. */
final readonly class ActionReceipt
{
    public function __construct(public ActionView $view)
    {
    }
}
