<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

/** Locked remains a preference within current permissions and incident restrictions. */
final readonly class DeploymentPreference
{
    public function __construct(public string $deployment, public bool $locked) { Values::set([$deployment]); }
    /**
     * @return array<string, string|bool>
     */
    public function toArray(): array { return ['deployment'=>$this->deployment, 'locked'=>$this->locked]; }
}
