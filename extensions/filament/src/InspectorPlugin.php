<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/** Host chooses the panel and its authentication middleware. No automatic panel registration. */
final class InspectorPlugin implements Plugin
{
    public function getId(): string { return 'agentools-inspector'; }
    public function register(Panel $panel): void { $panel->pages([Pages\Inspect::class]); }
    public function boot(Panel $panel): void {}
}
