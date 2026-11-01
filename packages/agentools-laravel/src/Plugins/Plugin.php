<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Plugins;

/** Trusted code installation only. Hook input grants no IO/effect authority. */
interface Plugin
{
    public function id(): string;
    public function apply(HookInput $input): Contribution;
    /** Clear every user/cache/diagnostic field, including after exceptions. */
    public function reset(): void;
}
