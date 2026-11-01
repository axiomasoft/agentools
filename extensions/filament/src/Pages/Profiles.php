<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Pages;

use AgenToolsPlatform\Filament\Profiles\{Admin, Operation, Request, Result};
use Filament\Pages\Page;

final class Profiles extends Page
{
    protected string $view = 'agentools-inspector::profiles';
    protected static ?string $slug = 'agentools-profiles';
    protected static bool $shouldRegisterNavigation = false;

    public string $target = '';
    public int $version = 1;
    public string $hash = '';
    public int $expected = 0;
    public string $manifest = '';
    public string $instance = '';
    public string $command = '';
    public string $reason = '';
    private string $feedback = '';

    public function draft(): void { $this->act(Operation::Draft); }
    public function validateDraft(): void { $this->act(Operation::Validate); }
    public function publish(): void { $this->act(Operation::Publish); }
    public function assign(): void { $this->act(Operation::Assign); }
    public function deprecate(): void { $this->act(Operation::Deprecate); }
    public function retireProfile(): void { $this->act(Operation::Retire); }
    public function migrateSession(): void { $this->act(Operation::Migrate); }

    private function act(Operation $operation): void
    {
        $result = $this->run($operation);
        $this->feedback = $result->code;
        if ($operation === Operation::Draft && $result->code === 'ok') {
            $bundle = $result->fields['bundle'] ?? null;
            if (is_array($bundle) && is_array($bundle['ref'] ?? null) && is_string($bundle['ref']['id'] ?? null)
                && is_int($bundle['version'] ?? null) && is_string($bundle['hash'] ?? null)) {
                $this->target = $bundle['ref']['id']; $this->version = $bundle['version']; $this->hash = $bundle['hash'];
            }
        }
        if ($result->code === 'ok' && is_int($result->fields['revision'] ?? null)) {
            $this->expected = $result->fields['revision'];
        }
        $this->manifest = '';
    }

    private function run(Operation $operation): Result
    {
        try {
            return app(Admin::class)->execute(new Request($operation, $this->target, $this->version, $this->hash,
                $this->expected, $this->manifest, $this->instance, $this->command, $this->reason));
        } catch (\Throwable) { return new Result('unavailable'); }
    }

    /** No result/authority survives in the Livewire snapshot; every render reads under current rights. */
    /** @return array{result: Result, feedback: string} */
    protected function getViewData(): array { return ['result' => $this->run(Operation::Inspect), 'feedback' => $this->feedback]; }
}
