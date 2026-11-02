<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};
use AgenToolsPlatform\Core\Models\Capability;

/** All tuple components are required, even for generic text. Suite versions are content hashes. */
final readonly class RouteRequest
{
    /**
     * @param list<string> $regions
     * @param list<string> $dataClasses
     * @param list<Capability> $capabilities
     * @param array<string,string> $suiteVersions */
    public function __construct(public Ref $subject, public Ref $host, public Ref $bundle, public string $task,
        public string $purpose, public array $regions, public array $dataClasses, public array $capabilities,
        public array $suiteVersions, public PayloadHash $promptHash, public PayloadHash $skillsHash,
        public PayloadHash $toolsHash, public PayloadHash $corpusHash, public ?string $knownRoute = null)
    {
        $subject->tenant->assertSame($host->tenant); $subject->tenant->assertSame($bundle->tenant);
        if ($task === '' || $purpose === '' || $regions === [] || $capabilities === [] || $suiteVersions === []) {
            throw new \InvalidArgumentException('Explicit route constraints and qualification suites required');
        }
        foreach ($suiteVersions as $name => $hash) {
            if ($name === '') { throw new \InvalidArgumentException('Suite identity required'); }
            new \AgenToolsPlatform\Core\Common\PayloadHash($hash);
        }
        foreach (array_merge($regions, $dataClasses) as $value) {
            if ($value === '') { throw new \InvalidArgumentException('Empty route constraint'); }
        }
    }
    public function audio(): bool { return in_array(Capability::Audio, $this->capabilities, true); }
}
