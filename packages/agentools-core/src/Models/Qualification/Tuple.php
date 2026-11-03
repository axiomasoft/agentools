<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Qualification;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, TenantId};
use AgenToolsPlatform\Core\Models\Routing\{CatalogEntry, QualificationTuple, RouteRequest};

/** Includes the original routing tuple; policy and profile hashes add restrictions. */
final readonly class Tuple
{
    private const array FIELDS = ['deployment_ref','model_revision','profile_ref','profile_bundle_ref',
        'profile_bundle_hash','task_ref','catalog_hash','routing_hash','prompt_hash','skills_hash',
        'tool_schema_hash','material_corpus_hash','policy_hash','evidence_policy_hash'];

    /**
     * @param array<string,string> $components
     * @param array<string,string> $suiteVersions
     */
    public function __construct(public TenantId $tenant, public array $components, public array $suiteVersions)
    {
        $keys = array_keys($components); $expected = self::FIELDS; sort($keys); sort($expected);
        if ($keys !== $expected || $suiteVersions === []) { throw new \InvalidArgumentException('Exact qualification tuple required'); }
        foreach ($components as $key => $value) {
            if ($value === '') { throw new \InvalidArgumentException('Empty tuple component'); }
            if (str_ends_with($key,'_hash')) { new \AgenToolsPlatform\Core\Common\PayloadHash($value); }
        }
        foreach ($suiteVersions as $name => $hash) {
            if ($name === '') { throw new \InvalidArgumentException('Suite identity required'); }
            new \AgenToolsPlatform\Core\Common\PayloadHash($hash);
        }
    }

    public static function forRoute(RouteRequest $request, CatalogEntry $entry, Requirements $requirements, CanonicalHasher $hasher): self
    {
        $tenant = $request->subject->tenant; $tenant->assertSame($entry->deployment->ref->tenant);
        $tenant->assertSame($requirements->profile->tenant); $tenant->assertSame($requirements->evidencePolicy->ref->tenant);
        return new \AgenToolsPlatform\Core\Models\Qualification\Tuple($tenant, [
            'deployment_ref'=>$entry->deployment->ref->value,'model_revision'=>$entry->deployment->model,
            'profile_ref'=>$requirements->profile->value,'profile_bundle_ref'=>$request->bundle->value,
            'profile_bundle_hash'=>$requirements->bundleHash->value,'task_ref'=>$request->task,
            'catalog_hash'=>$entry->hash->value,'routing_hash'=>QualificationTuple::hash($request,$entry,$hasher)->value,
            'prompt_hash'=>$request->promptHash->value,'skills_hash'=>$request->skillsHash->value,
            'tool_schema_hash'=>$request->toolsHash->value,'material_corpus_hash'=>$request->corpusHash->value,
            'policy_hash'=>$requirements->policyHash->value,'evidence_policy_hash'=>$requirements->evidencePolicy->hash($hasher)->value,
        ], $request->suiteVersions);
    }
    public function hash(CanonicalHasher $hasher): PayloadHash
    { return $hasher->hash($this->tenant,'qualification-tuple',$this->toArray()); }
    /** @return array<string,mixed> */
    public function toArray(): array
    { $suites = $this->suiteVersions; ksort($suites); return ['tenant_ref'=>$this->tenant->value,'components'=>$this->components,'suite_versions'=>$suites]; }
    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    { return new \AgenToolsPlatform\Core\Models\Qualification\Tuple(new \AgenToolsPlatform\Core\Common\TenantId(Data::text($data,'tenant_ref')),Data::map($data['components'] ?? null),Data::map($data['suite_versions'] ?? null)); }
}
