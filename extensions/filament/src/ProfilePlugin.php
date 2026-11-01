<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/** Separate explicit opt-in: existing read-only inspector panels stay read-only. */
final class ProfilePlugin implements Plugin
{
    public function getId(): string { return 'agentools-profile-publish'; }
    public function register(Panel $panel): void { $panel->pages([Pages\Profiles::class]); }
    public function boot(Panel $panel): void {}
}
