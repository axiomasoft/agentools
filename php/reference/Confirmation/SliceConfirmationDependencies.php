<?php

declare(strict_types=1);

namespace AgenTools\Reference\Confirmation;

use AgenTools\Confirmation\ApprovalEvidence;
use AgenTools\Confirmation\ApprovalRequirement;
use AgenTools\Confirmation\AuthenticatedActor;
use AgenTools\Confirmation\AuthorizationContext;
use AgenTools\Confirmation\AuthorizationDecision;
use AgenTools\Confirmation\AuthorizationRequest;
use AgenTools\Confirmation\ConfirmationProtocol;
use AgenTools\Confirmation\ConfirmationRecord;
use AgenTools\Confirmation\ConfirmationService;
use AgenTools\TestKit\InMemoryConfirmationTokenStore;
use AgenTools\Confirmation\ResolvedPolicy;
use AgenTools\Reference\Crypto\TenantTokenKeyProvider;
use AgenTools\Contracts\ApprovalEvidenceReader;
use AgenTools\Contracts\AuthenticatedActorProvider;
use AgenTools\Contracts\AuthorizationContextResolver;
use AgenTools\Contracts\Clock;
use AgenTools\Contracts\ConfirmationAuthorizer;
use AgenTools\Contracts\ConfirmationPolicyResolver;
use AgenTools\Contracts\ConfirmationRecordStore;
use AgenTools\Contracts\ToolDescriptorRegistry;
use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Schema\Canonicalizer;
use AgenTools\Tool\ToolDescriptor;

/** Trusted confirmation ports for the reference slice (test/reference contour only). */
final readonly class SliceConfirmationBundle
{
    public function __construct(
        public ConfirmationService $service,
        public ConfirmationProtocol $protocol,
    ) {
    }
}

final class SliceConfirmationDependencies
{
    public static function bundle(
        RegistryToolPort $tools,
        Clock $clock,
        string $tenantId,
        string $principalId,
        string $resourceId,
        Canonicalizer $canonicalizer,
        string $secret = 'slice-reference-secret',
    ): SliceConfirmationBundle {
        $keys = new TenantTokenKeyProvider($tenantId, $secret);
        $store = new InMemoryConfirmationTokenStore();
        $issuer = 'agentools';
        $audience = 'confirmation';
        $protocol = new ConfirmationProtocol($keys, $store, $issuer, $audience);
        $records = new InMemoryConfirmationRecordStore();

        $service = new ConfirmationService(
            $protocol,
            $keys,
            $store,
            $issuer,
            $audience,
            new FixedActorProvider(new AuthenticatedActor($principalId, $tenantId)),
            $clock,
            new RegistryToolDescriptorRegistry($tools),
            new FixedAuthorizationContext($tenantId, $resourceId),
            new FixedConfirmationPolicy($principalId),
            $records,
            new NullApprovalEvidenceReader(),
            new AllowConfirmationAuthorizer(),
            $canonicalizer,
        );

        return new SliceConfirmationBundle($service, $protocol);
    }

    public static function service(
        RegistryToolPort $tools,
        Clock $clock,
        string $tenantId,
        string $principalId,
        string $resourceId,
        Canonicalizer $canonicalizer,
        string $secret = 'slice-reference-secret',
    ): ConfirmationService {
        return self::bundle($tools, $clock, $tenantId, $principalId, $resourceId, $canonicalizer, $secret)->service;
    }
}

final class FixedActorProvider implements AuthenticatedActorProvider
{
    public function __construct(private readonly AuthenticatedActor $actor)
    {
    }

    public function currentActor(): AuthenticatedActor
    {
        return $this->actor;
    }
}

final class RegistryToolDescriptorRegistry implements ToolDescriptorRegistry
{
    public function __construct(private readonly RegistryToolPort $tools)
    {
    }

    public function descriptor(string $toolName): ?ToolDescriptor
    {
        foreach ($this->tools->discover() as $descriptor) {
            if ($descriptor->name === $toolName) {
                return $descriptor;
            }
        }

        return null;
    }
}

final class FixedAuthorizationContext implements AuthorizationContextResolver
{
    public function __construct(private readonly string $tenantId, private readonly string $resourceId)
    {
    }

    public function resolveContext(AuthenticatedActor $actor, string $actionId, string $toolName): AuthorizationContext
    {
        return new AuthorizationContext($this->tenantId, $this->resourceId);
    }
}

final class FixedConfirmationPolicy implements ConfirmationPolicyResolver
{
    public function __construct(private readonly string $commitPrincipal)
    {
    }

    public function resolvePolicy(
        AuthenticatedActor $actor,
        AuthorizationContext $context,
        string $actionId,
        string $toolName,
    ): ResolvedPolicy {
        return new ResolvedPolicy(
            policyId: 'reference-slice',
            revision: '1',
            commitPrincipal: $this->commitPrincipal,
            approvalRequirement: ApprovalRequirement::none(),
            validUntil: new \DateTimeImmutable('+1 day'),
        );
    }
}

final class InMemoryConfirmationRecordStore implements ConfirmationRecordStore
{
    /** @var array<string, ConfirmationRecord> */
    private array $records = [];

    public function save(ConfirmationRecord $record): void
    {
        $key = $record->tenantId . '|' . $record->proposal->proposalId . '|' . $record->proposal->proposalVersion;
        $this->records[$key] = $record;
    }

    public function load(string $proposalId, int $proposalVersion): ?ConfirmationRecord
    {
        foreach ($this->records as $record) {
            if ($record->proposal->proposalId === $proposalId && $record->proposal->proposalVersion === $proposalVersion) {
                return $record;
            }
        }

        return null;
    }
}

final class NullApprovalEvidenceReader implements ApprovalEvidenceReader
{
    public function read(ConfirmationRecord $record): ?ApprovalEvidence
    {
        return null;
    }
}

final class AllowConfirmationAuthorizer implements ConfirmationAuthorizer
{
    public function authorize(AuthorizationRequest $request): AuthorizationDecision
    {
        return AuthorizationDecision::allow();
    }
}
