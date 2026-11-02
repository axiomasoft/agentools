<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Budget;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Usage\{Amount, Units, Values};

/** Additive strict request builder; the existing runtime AdmissionRequest remains compatible. */
final readonly class AdmissionScope
{
    /** @param array<string,mixed> $content */
    private function __construct(public Ref $operationKey, public array $content, public UtcInstant $deadline) {}
    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        Values::keys($d,['operation_key','tenant_ref','actor_ref','subject_ref','purpose','capability','task_type','turn_ref','run_ref',
            'invocation_ref','business_operation_ref','bundle_ref','deployment_ref','estimated_units','estimated_amount','pricing_version','desired_deadline','profile_hash','context_hash','policy_versions']);
        $tenant = new \AgenToolsPlatform\Core\Common\TenantId(Values::text($d['tenant_ref']));
        foreach (['operation_key','subject_ref','turn_ref','run_ref','invocation_ref','business_operation_ref','bundle_ref','deployment_ref'] as $key) {
            new \AgenToolsPlatform\Core\Common\Ref($tenant,Values::text($d[$key]));
        }
        if ($d['actor_ref'] !== null) { new \AgenToolsPlatform\Core\Common\Ref($tenant,Values::text($d['actor_ref'])); }
        foreach (['purpose','capability','task_type'] as $key) { Values::text($d[$key]); }
        Units::fromArray(Values::object($d['estimated_units'])); Values::optionalText($d['pricing_version']);
        Amount::decode($d['estimated_amount'],true);
        foreach (['profile_hash','context_hash'] as $key) { new \AgenToolsPlatform\Core\Common\PayloadHash(Values::text($d[$key])); }
        $policies = Values::object($d['policy_versions']);
        if ($policies === []) { throw new \InvalidArgumentException('Current policy versions required'); }
        foreach ($policies as $key=>$version) { Values::text($key); Values::text($version); }
        $deadline = new \AgenToolsPlatform\Core\Common\UtcInstant(Values::text($d['desired_deadline'])); $d['desired_deadline'] = $deadline->value;
        return new \AgenToolsPlatform\Core\Budget\AdmissionScope(new \AgenToolsPlatform\Core\Common\Ref($tenant,Values::text($d['operation_key'])),$d,$deadline);
    }
    public function request(CanonicalHasher $hasher): AdmissionRequest { return new \AgenToolsPlatform\Core\Budget\AdmissionRequest($this->operationKey,$this->content,$hasher); }
}
