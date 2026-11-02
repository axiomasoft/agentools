<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Knowledge;

use AgenToolsPlatform\Knowledge\{Extraction,Material,SourceDocument,ValidationResult,Validator};

/** Optional Pipeline hook. Missing domain gold cannot certify a domain-gated release. */
final readonly class HostValidator implements Validator
{
    public function __construct(private ?DomainGold $gold) {}
    public function validate(Material $material, SourceDocument $source, Extraction $extraction): ValidationResult
    {
        if ($this->gold === null) { return new ValidationResult('unknown',['domain_gold_missing']); }
        try { return $this->gold->check($material,$source,$extraction); }
        catch (\Throwable) { return new ValidationResult('unknown',['domain_gold_unavailable']); }
    }
    /** Fixture validation never upgrades itself into a live educational qualification.
     * @return array<string,string|null>
     */
    public function qualification(): array
    {
        return ['educational_qualification'=>'insufficient_evidence','evidence_kind'=>'host_contract_harness',
            'domain_gold_ref'=>$this->gold?->evidenceRef(),'reason'=>'External expert baseline, scorer and deployment qualification are outside this synthetic slice.'];
    }
}
