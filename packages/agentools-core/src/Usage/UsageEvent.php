<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, InvocationId, PayloadHash, Ref, TenantId, UtcInstant};

/** One immutable replacement fact. The owner stores/exports it; this value is not a metering ledger. */
final readonly class UsageEvent
{
    /** @param array<string,mixed> $document */
    private function __construct(public Ref $id, public InvocationId $invocation, public int $version, public Producer $producer,
        public Certainty $certainty, public Units $units, public ?Amount $providerAmount, public ?Amount $estimate,
        public ?string $supersedes, public PayloadHash $hash, private array $document) {}
    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, CanonicalHasher $hasher): self
    {
        Values::keys($d,['schema_version','event_id','source','tenant_ref','actor_ref','subject_ref','assistant_session_ref','turn_ref','run_ref',
            'business_operation_ref','deployment_ref','model_revision','correlation_id','certainty','occurred_at','units','provider_amount','estimate','pricing_version','supersedes_event_id']);
        if ($d['schema_version'] !== '1') { throw new \InvalidArgumentException('Unknown usage schema'); }
        $tenant = new \AgenToolsPlatform\Core\Common\TenantId(Values::text($d['tenant_ref']));
        $source = Values::object($d['source']); Values::keys($source,['kind','invocation_id','usage_version','producer','mapping_ref']);
        if ($source['kind'] !== 'agentools.invocation') { throw new \InvalidArgumentException('Unknown usage source'); }
        $version = Values::integer($source['usage_version']);
        if ($version === 0) { throw new \InvalidArgumentException('Positive usage version required'); }
        foreach (['assistant_session_ref','turn_ref','run_ref','business_operation_ref','deployment_ref'] as $key) {
            new \AgenToolsPlatform\Core\Common\Ref($tenant,Values::text($d[$key]));
        }
        foreach (['actor_ref','subject_ref'] as $key) { if ($d[$key] !== null) { new \AgenToolsPlatform\Core\Common\Ref($tenant,Values::text($d[$key])); } }
        Values::optionalText($d['model_revision']); Values::text($d['correlation_id']); Values::optionalText($d['pricing_version']); Values::text($source['mapping_ref']);
        $d['occurred_at'] = (new \AgenToolsPlatform\Core\Common\UtcInstant(Values::text($d['occurred_at'])))->value;
        $units = Units::fromArray(Values::object($d['units'])); $amount = Amount::decode($d['provider_amount']); $estimate = Amount::decode($d['estimate'],true);
        $certainty = Certainty::from(Values::text($d['certainty']));
        if ($certainty === Certainty::Unknown && ($amount !== null || $units->toArray() !== Units::unknown(self::requestCount($units))->toArray())) {
            throw new \InvalidArgumentException('Unknown provider outcome cannot fabricate actual quantities');
        }
        $supersedes = Values::optionalText($d['supersedes_event_id']);
        if (($version === 1) !== ($supersedes === null) || $supersedes === $d['event_id']) { throw new \InvalidArgumentException('Explicit immutable correction chain required'); }
        return new \AgenToolsPlatform\Core\Usage\UsageEvent(new \AgenToolsPlatform\Core\Common\Ref($tenant,Values::text($d['event_id'])),
            new \AgenToolsPlatform\Core\Common\InvocationId($tenant,Values::text($source['invocation_id'])),$version,Producer::from(Values::text($source['producer'])),
            $certainty,$units,$amount,$estimate,$supersedes,$hasher->hash($tenant,'canonical-usage-event',$d),$d);
    }
    private static function requestCount(Units $units): ?int
    { $value = $units->toArray()['request_count']; return $value === null ? null : Values::integer($value); }
    /** @return array<string,mixed> */
    public function toArray(): array { return $this->document; }
    /** @return array<string,mixed> */
    public function binding(): array
    {
        $d = $this->document; foreach (['schema_version','event_id','source','occurred_at','certainty','units','provider_amount','estimate','pricing_version','supersedes_event_id'] as $key) { unset($d[$key]); }
        $d['producer'] = $this->producer->value; ksort($d); return $d;
    }
    public function acknowledgement(): string { return 'usage-ack:'.substr($this->hash->value,8); }
}
