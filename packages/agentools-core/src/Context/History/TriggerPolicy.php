<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

final readonly class TriggerPolicy
{
    public function __construct(public int $tokenThreshold,public int $rebuildEveryVersions)
    {
        Checks::positive($tokenThreshold);Checks::positive($rebuildEveryVersions);
    }
    public function due(int $observedTokens,bool $segmentComplete,?Trigger $transition,int $versionsSinceExactRebuild): ?Trigger
    {
        if ($observedTokens<0 || $versionsSinceExactRebuild<0) { throw new \InvalidArgumentException('Unknown counts cannot be zero'); }
        if ($versionsSinceExactRebuild>=$this->rebuildEveryVersions) { return Trigger::PeriodicRebuild; }
        if ($transition!==null) { return $transition; }
        if ($segmentComplete) { return Trigger::Segment; }
        return $observedTokens>=$this->tokenThreshold ? Trigger::Threshold : null;
    }
}
