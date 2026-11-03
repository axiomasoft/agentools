<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Testing\Providers;
use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};
use AgenToolsPlatform\Core\Models\Time;
use AgenToolsPlatform\Core\Models\{Deployment,ModelRequest};

/** Explicit synthetic-data/test-credential/spend permission, supplied by the host, never a default. */
final readonly class ProbePolicy
{
    public function __construct(public Ref $testCredential,public Ref $authorization,public bool $enabled,public bool $syntheticData,
        public int $maxCalls,public int $maxDataBytes,public int $maxOutputTokens,public int $ceilingMicros,
        public ?int $inputPriceUpperMicros,public ?int $outputPriceUpperMicros,public ?string $currency,public ?string $pricingVersion,
        public Ref $pricingEvidence,public UtcInstant $pricingExpiresAt,public UtcInstant $deadline,public string $approvedPayloadHash,public Deployment $deployment)
    {
        $testCredential->tenant->assertSame($authorization->tenant); $testCredential->tenant->assertSame($pricingEvidence->tenant);
        $testCredential->tenant->assertSame($deployment->ref->tenant);
        if ($maxCalls<1 || $maxCalls>10 || $maxDataBytes<1 || $maxDataBytes>8192 || $maxOutputTokens<1 || $maxOutputTokens>256
            || $ceilingMicros<0 || $ceilingMicros>1000000000 || preg_match('/^[0-9a-f]{64}$/D',$approvedPayloadHash)!==1) { throw new \InvalidArgumentException('Bounded opt-in probe policy required'); }
    }
    public function ceiling(int $bytes,int $output,UtcInstant $now): int
    {
        if (!$this->enabled || !$this->syntheticData || Time::compare($now,$this->deadline)>=0 || Time::compare($now,$this->pricingExpiresAt)>=0
            || $this->inputPriceUpperMicros===null || $this->outputPriceUpperMicros===null || $this->currency===null || $this->currency===''
            || $this->pricingVersion===null || $this->pricingVersion==='' || $bytes<1 || $bytes>$this->maxDataBytes || $output<1 || $output>$this->maxOutputTokens) {
            throw new \LogicException('Probe opt-in/data/pricing guard denied');
        }
        foreach ([$this->inputPriceUpperMicros,$this->outputPriceUpperMicros] as $price) {
            if ($price<0 || $price>1000000000) { throw new \InvalidArgumentException('Explicit nonnegative price upper bound required'); }
        }
        // Conservative byte-token upper bound, not a claim to a model-specific exact tokenizer.
        $bound=$bytes*$this->inputPriceUpperMicros+$output*$this->outputPriceUpperMicros;
        if ($bound>$this->ceilingMicros) { throw new \LogicException('Probe spend ceiling exceeded'); } return $bound;
    }
    public static function payloadHash(ModelRequest $request): string
    {
        $data=get_object_vars($request);
        foreach (['cancellation','invocationId','contextSnapshotRef','contextSnapshotHash','credentialRef','deadline'] as $field) { unset($data[$field]); }
        return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
    }
}
