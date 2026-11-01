<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

final readonly class HardConstraints
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
     * @var list<string>
     */ public array $requiredCapabilities;

    /**
     * @param array<array-key, mixed> $regions
     * @param array<array-key, mixed> $deployments
     * @param array<array-key, mixed> $dataScopes
     * @param array<array-key, mixed> $tools
     * @param array<array-key, mixed> $capabilities
     * @param array<array-key, mixed> $requiredCapabilities
     */
    public function __construct(array $regions, array $deployments, array $dataScopes, array $tools,
        public int $budgetUnits, public ConfirmationFloor $confirmation, public SafetyFloor $safety,
        public Denials $denied = new \AgenToolsPlatform\Core\Profiles\Composition\Denials(), array $capabilities = [], array $requiredCapabilities = [])
    {
        if ($budgetUnits < 0 || $budgetUnits > 9007199254740991) { throw new \InvalidArgumentException('Explicit bounded budget in host admission units required'); }
        $this->regions = Values::set($regions); $this->deployments = Values::set($deployments);
        $this->dataScopes = Values::set($dataScopes); $this->tools = Values::set($tools);
        $this->capabilities = Values::set($capabilities); $this->requiredCapabilities = Values::set($requiredCapabilities);
    }
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['regions'=>$this->regions, 'deployments'=>$this->deployments, 'data_scopes'=>$this->dataScopes,
            'tools'=>$this->tools, 'capabilities'=>$this->capabilities, 'required_capabilities'=>$this->requiredCapabilities,
            'forbidden'=>$this->denied->toArray(), 'budget_units'=>$this->budgetUnits,
            'confirmation_floor'=>$this->confirmation->value, 'safety_floor'=>$this->safety->value];
    }
}
