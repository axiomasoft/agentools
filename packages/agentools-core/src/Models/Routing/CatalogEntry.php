<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Models\{Capability, CapabilityEvidence, Deployment, Evidence, Support};

/** Validated immutable catalog revision. No credentials, provider prices or brand defaults are discovered here. */
final readonly class CatalogEntry
{
    /**
     * @param list<string> $dataClasses
     * @param array<string,mixed> $document
     */
    private function __construct(public Deployment $deployment, public Ref $credential, public Ref $snapshot,
        public PayloadHash $hash, public string $endpoint, public int $contextLimit, public int $outputLimit,
        public array $dataClasses, public CapabilityEvidence $terms, public CapabilityEvidence $modelEvidence,
        public int $quality, public ?int $latencyMs, public ?int $costMinor, public ?string $pricingVersion,
        public ?CapabilityEvidence $pricingEvidence, private array $document) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, CanonicalHasher $hasher): self
    {
        self::keys($data, ['format','tenant','id','provider','account','region','model_revision','credential_ref',
            'snapshot_ref','endpoint','context_limit','output_limit','data_classes','terms','model_evidence',
            'capabilities','quality','latency_ms','cost_minor','pricing_version','pricing_evidence']);
        if ($data['format'] !== 'deployment-catalog/v1') { throw new \InvalidArgumentException('Unknown catalog format'); }
        $tenant = new \AgenToolsPlatform\Core\Common\TenantId(self::text($data, 'tenant'));
        $classes = $data['data_classes'];
        if (!is_array($classes) || !array_is_list($classes)) { throw new \InvalidArgumentException('Data classes must be a list'); }
        foreach ($classes as $value) { if (!is_string($value) || $value === '') { throw new \InvalidArgumentException('Invalid data class'); } }
        if (count(array_unique($classes)) !== count($classes)) { throw new \InvalidArgumentException('Duplicate data class'); }
        $caps = self::object($data['capabilities']); $capabilities = [];
        foreach ($caps as $name => $evidence) {
            if (Capability::tryFrom($name) === null) { throw new \InvalidArgumentException('Unknown capability'); }
            $capabilities[$name] = self::evidence($evidence);
        }
        $pricingVersion = $data['pricing_version']; $pricing = $data['pricing_evidence'];
        if (($pricingVersion === null) !== ($pricing === null) || ($pricingVersion !== null && (!is_string($pricingVersion) || $pricingVersion === ''))
            || ($data['cost_minor'] !== null && $pricing === null)) { throw new \InvalidArgumentException('Pricing needs version and evidence'); }
        $endpoint = self::text($data, 'endpoint');
        if (preg_match('~^https://[^@?#]+$~D', $endpoint) !== 1) {
            throw new \InvalidArgumentException('HTTPS endpoint without embedded credentials/query required');
        }
        $context = self::integer($data['context_limit']); $output = self::integer($data['output_limit']);
        if ($context < 1 || $output < 1 || $output > $context) { throw new \InvalidArgumentException('Invalid model limits'); }
        $latency = $data['latency_ms'] === null ? null : self::integer($data['latency_ms']);
        $cost = $data['cost_minor'] === null ? null : self::integer($data['cost_minor']);
        return new \AgenToolsPlatform\Core\Models\Routing\CatalogEntry(new \AgenToolsPlatform\Core\Models\Deployment(new \AgenToolsPlatform\Core\Common\Ref($tenant, self::text($data,'id')),
            self::text($data,'provider'), self::text($data,'account'), self::text($data,'region'), self::text($data,'model_revision'), $capabilities),
            new \AgenToolsPlatform\Core\Common\Ref($tenant,self::text($data,'credential_ref')),
            new \AgenToolsPlatform\Core\Common\Ref($tenant,self::text($data,'snapshot_ref')),
            $hasher->hash($tenant,'deployment-catalog',$data), $endpoint, $context, $output, $classes,
            self::evidence($data['terms']), self::evidence($data['model_evidence']), self::integer($data['quality']),
            $latency, $cost, $pricingVersion, $pricing === null ? null : self::evidence($pricing), $data);
    }
    public function costAt(UtcInstant $now): ?int { return $this->pricingEvidence?->permits($now) ? $this->costMinor : null; }
    /** @return array<string,mixed> */
    public function toArray(): array { return $this->document; }
    /** @param array<string,mixed> $data */
    private static function text(array $data, string $key): string
    { $v = $data[$key] ?? null; if (!is_string($v) || trim($v) === '') { throw new \InvalidArgumentException('Missing catalog string: '.$key); } return $v; }
    private static function integer(mixed $v): int
    { if (!is_int($v) || $v < 0 || $v > 9007199254740991) { throw new \InvalidArgumentException('Invalid catalog integer'); } return $v; }
    /** @return array<string,mixed> */
    private static function object(mixed $v): array
    {
        if (!is_array($v)) { throw new \InvalidArgumentException('Catalog object required'); }
        foreach ($v as $k => $_) { if (!is_string($k)) { throw new \InvalidArgumentException('Catalog object keys required'); } }
        return $v;
    }
    /**
     * @param array<string,mixed> $v
     * @param list<string> $keys
     */
    private static function keys(array $v, array $keys): void
    {
        if (array_diff(array_keys($v),$keys) !== [] || array_diff($keys,array_keys($v)) !== []) {
            throw new \InvalidArgumentException('Unknown or missing catalog field');
        }
    }
    private static function evidence(mixed $value): CapabilityEvidence
    {
        $e = self::object($value); self::keys($e,['support','evidence','checked_at','expires_at','evidence_ref']);
        return new \AgenToolsPlatform\Core\Models\CapabilityEvidence(Support::from(self::text($e,'support')), Evidence::from(self::text($e,'evidence')),
            new \AgenToolsPlatform\Core\Common\UtcInstant(self::text($e,'checked_at')), new \AgenToolsPlatform\Core\Common\UtcInstant(self::text($e,'expires_at')), self::text($e,'evidence_ref'));
    }
}
