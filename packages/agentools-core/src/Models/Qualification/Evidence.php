<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Qualification;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref, TenantId};

/** Trusted producer facts and original result hash; no provider IO or LLM judge. */
final readonly class Evidence
{
    private const array STATUSES = ['pass','fail','not_run','insufficient_evidence'];
    /**
     * @param list<string> $requiredCases
     * @param list<string> $executedCases
     * @param array<string,string> $scorers
     */
    public function __construct(public Ref $ref, public PayloadHash $sourceHash, public PayloadHash $tupleHash,
        public string $kind, public array $requiredCases, public array $executedCases, public array $scorers,
        public string $domain = 'insufficient_evidence', public string $live = 'not_run',
        public string $host = 'not_run', public string $pilot = 'not_run', public bool $publicSyntheticCorpus = true)
    {
        Data::strings($requiredCases); Data::strings($executedCases);
        Data::map($scorers);
        if (!in_array($kind,EvidencePolicy::KINDS,true) || array_diff(array_values($scorers),self::STATUSES) !== []
            || !in_array($domain,self::STATUSES,true) || !in_array($live,self::STATUSES,true)
            || !in_array($host,self::STATUSES,true) || !in_array($pilot,self::STATUSES,true)) {
            throw new \InvalidArgumentException('Canonical evidence facts required');
        }
    }
    public function decision(EvidencePolicy $policy): string
    {
        $this->ref->tenant->assertSame($policy->ref->tenant);
        if (!$this->publicSyntheticCorpus || ($this->scorers['technical.hard'] ?? null) === 'fail') { return 'rejected'; }
        foreach ($policy->requiredScorers as $scorer) {
            if (($this->scorers[$scorer] ?? null) === 'fail') { return 'rejected'; }
        }
        if (in_array($policy->scope,['live-provider','product-pilot'],true) && $this->live === 'fail') { return 'rejected'; }
        if ($policy->scope === 'product-pilot' && in_array('fail',[$this->domain,$this->host,$this->pilot],true)) { return 'rejected'; }
        if (in_array($policy->scope,['educational','product-pilot'],true) && ($this->scorers['owner.domain'] ?? null) === 'fail') { return 'rejected'; }
        if (!Data::set($this->requiredCases) || !Data::set($this->executedCases)
            || !$this->sameCases($policy->requiredCases,$this->requiredCases)
            || !$this->sameCases($policy->requiredCases,$this->executedCases)
            || !in_array($this->kind,$policy->allowedKinds,true) || in_array($this->kind,['design-schema','unit'],true)) { return 'insufficient_evidence'; }
        foreach ($policy->requiredScorers as $scorer) {
            if (($this->scorers[$scorer] ?? null) !== 'pass') { return 'insufficient_evidence'; }
        }
        if ($policy->scope === 'educational' && ($this->domain !== 'pass' || ($this->scorers['owner.domain'] ?? null) !== 'pass')) { return $this->domain === 'fail' ? 'rejected' : 'insufficient_evidence'; }
        if (in_array($policy->scope,['live-provider','product-pilot'],true)
            && (!in_array($this->kind,['live-provider','product-pilot'],true) || $this->live !== 'pass')) { return 'insufficient_evidence'; }
        if ($policy->scope === 'product-pilot' && ($this->kind !== 'product-pilot' || $this->host !== 'pass'
            || $this->pilot !== 'pass' || $this->domain !== 'pass' || ($this->scorers['owner.domain'] ?? null) !== 'pass')) { return 'insufficient_evidence'; }
        return 'qualified';
    }
    public function exitCode(EvidencePolicy $policy): int { return $this->decision($policy) === 'qualified' ? 0 : 1; }
    public function assertClaim(string $claim, EvidencePolicy $policy): void
    {
        $allowed = $this->decision($policy) === 'qualified' && match ($claim) {
            'technical-contract-only'=>true,
            'educational'=>in_array($policy->scope,['educational','product-pilot'],true) && $this->domain === 'pass' && ($this->scorers['owner.domain'] ?? null) === 'pass',
            'live_provider_ready'=>in_array($policy->scope,['live-provider','product-pilot'],true) && in_array($this->kind,['live-provider','product-pilot'],true) && $this->live === 'pass',
            'product_pilot_ready'=>$policy->scope === 'product-pilot' && $this->kind === 'product-pilot' && $this->live === 'pass' && $this->host === 'pass'
                && $this->pilot === 'pass' && $this->domain === 'pass' && ($this->scorers['owner.domain'] ?? null) === 'pass',
            default=>false,
        };
        if (!$allowed) { throw new \DomainException('EVIDENCE_SCOPE_MISMATCH'); }
    }
    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function sameCases(array $left, array $right): bool { sort($left); sort($right); return $left === $right; }
    /** @return array<string,mixed> */
    public function toArray(): array
    { return ['id'=>$this->ref->value,'source_hash'=>$this->sourceHash->value,'tuple_hash'=>$this->tupleHash->value,'kind'=>$this->kind,
        'required_cases'=>$this->requiredCases,'executed_cases'=>$this->executedCases,'scorers'=>$this->scorers,'domain'=>$this->domain,
        'live'=>$this->live,'host'=>$this->host,'pilot'=>$this->pilot,'public_synthetic_corpus'=>$this->publicSyntheticCorpus]; }
    /** @param array<string,mixed> $data */
    public static function fromArray(TenantId $tenant, array $data): self
    {
        if (!isset($data['public_synthetic_corpus']) || !is_bool($data['public_synthetic_corpus'])) { throw new \InvalidArgumentException('Corpus access policy required'); }
        return new \AgenToolsPlatform\Core\Models\Qualification\Evidence(new \AgenToolsPlatform\Core\Common\Ref($tenant,Data::text($data,'id')),new \AgenToolsPlatform\Core\Common\PayloadHash(Data::text($data,'source_hash')),new \AgenToolsPlatform\Core\Common\PayloadHash(Data::text($data,'tuple_hash')),
            Data::text($data,'kind'),Data::strings($data['required_cases'] ?? null),Data::strings($data['executed_cases'] ?? null),Data::map($data['scorers'] ?? null),
            Data::text($data,'domain'),Data::text($data,'live'),Data::text($data,'host'),Data::text($data,'pilot'),$data['public_synthetic_corpus']);
    }
}
