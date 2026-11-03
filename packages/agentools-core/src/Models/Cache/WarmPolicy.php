<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;
use AgenToolsPlatform\Core\Common\{PayloadHash,UtcInstant};
use AgenToolsPlatform\Core\Models\Time;

/** Technical scheduling bounds; these never grant owner admission or money. */
final readonly class WarmPolicy
{
    public function __construct(public PayloadHash $requestedProfile,public UtcInstant $windowStart,public UtcInstant $windowEnd,
        public int $maxOperations,public int $maxEstimatedUnits,public int $minimumPopularity,public int $idleSeconds,
        public ?WarmEconomics $economics=null,public bool $explicitWarmWithoutSavings=false)
    {
        if (Time::compare($windowStart,$windowEnd)>=0 || min($maxOperations,$maxEstimatedUnits,$minimumPopularity,$idleSeconds)<1
            || max($maxOperations,$maxEstimatedUnits,$minimumPopularity,$idleSeconds)>1000000000) { throw new \InvalidArgumentException('Bounded warm policy required'); }
    }
    public function assertFor(Key $key,UtcInstant $now,int $popularity,int $estimate): void
    {
        if ($key->dimensions['profile_hash']!==$this->requestedProfile->value || Time::compare($now,$this->windowStart)<0
            || Time::compare($now,$this->windowEnd)>=0 || $popularity<$this->minimumPopularity || $estimate<0 || $estimate>$this->maxEstimatedUnits
            || !in_array($key->kind,[Kind::Compiled,Kind::Fragment,Kind::ProviderResource],true) || $key->dimensions['personal']) {
            throw new \LogicException('Warm policy denied');
        }
        if (($this->economics?->evaluate()['status'] ?? 'unknown')!=='beneficial' && !$this->explicitWarmWithoutSavings) {
            throw new \LogicException('Unknown/nonpositive ROI does not authorize automatic warming');
        }
    }
}
