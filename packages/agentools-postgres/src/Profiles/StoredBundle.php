<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Profiles;

use AgenToolsPlatform\Core\Profiles\{BundleManifest, BundleState};

final readonly class StoredBundle
{
    /** @param list<string> $issues */
    public function __construct(public BundleManifest $manifest, public BundleState $state, public int $revision, public array $issues) {}
}
