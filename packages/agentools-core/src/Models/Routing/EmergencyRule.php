<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

/** Non-null dimensions match conjunctively; absent dimensions are wildcards. */
final readonly class EmergencyRule
{
    public function __construct(public string $id, public string $evidenceRef, public ?string $provider = null,
        public ?string $account = null, public ?string $deployment = null, public ?string $model = null,
        public ?string $region = null, public ?string $tenant = null, public ?string $task = null,
        public ?bool $audio = null)
    {
        $dimensions = [$provider, $account, $deployment, $model, $region, $tenant, $task, $audio];
        if ($id === '' || $evidenceRef === '' || array_filter($dimensions, static fn ($v): bool => $v !== null) === []
            || in_array('', $dimensions, true)) { throw new \InvalidArgumentException('Explicit emergency target and evidence required'); }
    }
    public function matches(RouteRequest $request, CatalogEntry $entry): bool
    {
        $d = $entry->deployment;
        foreach ([[$this->provider,$d->provider],[$this->account,$d->account],[$this->deployment,$d->ref->value],
            [$this->model,$d->model],[$this->region,$d->region],[$this->tenant,$d->ref->tenant->value],
            [$this->task,$request->task],[$this->audio,$request->audio()]] as [$expected,$actual]) {
            if ($expected !== null && $expected !== $actual) { return false; }
        }
        return true;
    }
}
