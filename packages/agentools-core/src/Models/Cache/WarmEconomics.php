<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;

/** Host-supplied measured/forecast inputs, never a provider price/support qualification. */
final readonly class WarmEconomics
{
    public function __construct(public int $uses,public int $prefixTokens,public ?int $inputPrice,public ?int $cachedPrice,
        public ?int $warmCost,public ?int $storageCost,public string $currency,public string $pricingVersion,
        public string $costCurrency,public string $costPricingVersion) {}
    /** @return array{status:string,gross_micros:?int,cost_micros:?int,net_micros:?int,guaranteed:false} */
    public function evaluate(): array
    { return Economics::evaluate($this->uses,$this->prefixTokens,$this->inputPrice,$this->cachedPrice,$this->warmCost,$this->storageCost,
        $this->currency,$this->pricingVersion,$this->costCurrency,$this->costPricingVersion); }
}
