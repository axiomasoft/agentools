<?php

declare(strict_types=1);

namespace AgenTools\Reference\Scenario;

use AgenTools\Confirmation\ConfirmationProtocol;
use AgenTools\Confirmation\ConfirmationService;
use AgenTools\TestKit\InMemoryConfirmationTokenStore;
use AgenTools\Contracts\Clock;
use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Reference\Crypto\TenantTokenKeyProvider;
use AgenTools\Schema\Canonicalizer;

final readonly class ReferenceConfirmationServiceFactory
{
    /** @param list<string> $permissions */
    public function create(
        RegistryToolPort $tools,
        Clock $clock,
        string $tenantId,
        string $actorId,
        array $permissions,
    ): ConfirmationService {
        return $this->bundle($tools, $clock, $tenantId, $actorId, $permissions)->service;
    }

    /** @param list<string> $permissions */
    public function bundle(
        RegistryToolPort $tools,
        Clock $clock,
        string $tenantId,
        string $actorId,
        array $permissions,
    ): ReferenceConfirmationBundle {
        $trusted = new ReferenceConfirmationDependencies($tools, $clock, $tenantId, $actorId, $permissions);
        $keys = new TenantTokenKeyProvider($tenantId, 'reference-slice-secret');
        $store = new InMemoryConfirmationTokenStore();
        $issuer = 'agentools';
        $audience = 'confirmation';
        $protocol = new ConfirmationProtocol($keys, $store, $issuer, $audience);

        return new ReferenceConfirmationBundle(
            new ConfirmationService(
                $protocol,
                $keys,
                $store,
                $issuer,
                $audience,
                $trusted,
                $clock,
                $trusted,
                $trusted,
                $trusted,
                $trusted,
                $trusted,
                $trusted,
                new Canonicalizer(),
            ),
            $protocol,
        );
    }
}

final readonly class ReferenceConfirmationBundle
{
    public function __construct(
        public ConfirmationService $service,
        public ConfirmationProtocol $protocol,
    ) {
    }
}
