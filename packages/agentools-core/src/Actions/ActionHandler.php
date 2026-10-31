<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

/** Opaque owner binding supplied by code. The pure core never invokes effects. */
interface ActionHandler
{
    public function handlerId(): string;
}
