<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Pages;

use AgenToolsPlatform\Filament\{Inspection, Inspector};
use Filament\Pages\Page;
use Livewire\Attributes\Url;

final class Inspect extends Page
{
    protected string $view = 'agentools-inspector::inspect';
    protected static ?string $slug = 'agentools-inspect';
    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public string $kind = 'run';
    #[Url]
    public string $target = '';

    /** Re-evaluated on every render; no projected data/authority in the Livewire snapshot. */
    /** @return array{inspection: Inspection} */
    protected function getViewData(): array
    {
        try { $result = app(Inspector::class)->inspect($this->kind, $this->target); }
        catch (\Throwable) { $result = new Inspection('read_failed'); }
        return ['inspection' => $result];
    }
}
