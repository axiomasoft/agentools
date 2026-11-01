<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

final readonly class Denials
{
    /**
     * @var list<string>
     */ public array $regions;
    /**
     * @var list<string>
     */ public array $deployments;
    /**
     * @var list<string>
     */ public array $dataScopes;
    /**
     * @var list<string>
     */ public array $tools;
    /**
     * @var list<string>
     */ public array $capabilities;

    /**
     * @param array<array-key, mixed> $regions
     * @param array<array-key, mixed> $deployments
     * @param array<array-key, mixed> $dataScopes
     * @param array<array-key, mixed> $tools
     * @param array<array-key, mixed> $capabilities
     */
    public function __construct(array $regions = [], array $deployments = [], array $dataScopes = [], array $tools = [], array $capabilities = [])
    {
        $this->regions = Values::set($regions); $this->deployments = Values::set($deployments);
        $this->dataScopes = Values::set($dataScopes); $this->tools = Values::set($tools); $this->capabilities = Values::set($capabilities);
    }
    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array { return ['regions'=>$this->regions, 'deployments'=>$this->deployments, 'data_scopes'=>$this->dataScopes, 'tools'=>$this->tools, 'capabilities'=>$this->capabilities]; }
}
