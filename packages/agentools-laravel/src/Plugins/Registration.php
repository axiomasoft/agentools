<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Plugins;

final readonly class Registration
{
    public function __construct(public Manifest $manifest,public Plugin $plugin)
    {
        if ($plugin->id()!==$manifest->ref->version->id) { throw new \InvalidArgumentException('Code plugin binding conflict'); }
    }
}
