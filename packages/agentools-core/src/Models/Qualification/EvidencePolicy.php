<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Qualification;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, Ref, TenantId};

/** Trusted owner policy, not model configuration. No implicit evidence promotion. */
final readonly class EvidencePolicy
{
    public const array KINDS = ['design-schema','unit','contract-scripted','isolated-postgres','local-host-e2e','live-provider','product-pilot'];
    /**
     * @param list<string> $requiredCases
     * @param list<string> $requiredScorers
     * @param list<string> $allowedKinds
     */
    public function __construct(public Ref $ref, public array $requiredCases, public array $requiredScorers,
        public array $allowedKinds, public string $scope = 'technical-contract-only')
    {
        Data::strings($requiredCases,true); Data::strings($requiredScorers,true); Data::strings($allowedKinds,true);
        if (!Data::set($requiredCases) || !Data::set($requiredScorers) || !Data::set($allowedKinds)
            || !in_array('technical.hard',$requiredScorers,true) || array_diff($allowedKinds,self::KINDS) !== []
            || !in_array($scope,['technical-contract-only','educational','live-provider','product-pilot'],true)) {
            throw new \InvalidArgumentException('Explicit qualification evidence policy required');
        }
    }
    public function hash(CanonicalHasher $hasher): PayloadHash { return $hasher->hash($this->ref->tenant,'qualification-evidence-policy',$this->toArray()); }
    /** @return array<string,mixed> */
    public function toArray(): array
    { return ['id'=>$this->ref->value,'required_cases'=>$this->requiredCases,'required_scorers'=>$this->requiredScorers,'allowed_kinds'=>$this->allowedKinds,'scope'=>$this->scope]; }
    /** @param array<string,mixed> $data */
    public static function fromArray(TenantId $tenant, array $data): self
    { return new \AgenToolsPlatform\Core\Models\Qualification\EvidencePolicy(new \AgenToolsPlatform\Core\Common\Ref($tenant,Data::text($data,'id')),Data::strings($data['required_cases'] ?? null,true),Data::strings($data['required_scorers'] ?? null,true),Data::strings($data['allowed_kinds'] ?? null,true),Data::text($data,'scope')); }
}
