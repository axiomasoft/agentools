<?php

declare(strict_types=1);

namespace AgenTools\Action;

/** ALC-27: a zombie executor attempted to write an effect event. */
final class StaleExecutorEpoch extends LifecycleException
{
    public function __construct(int $eventEpoch, int $currentEpoch)
    {
        parent::__construct(sprintf('Executor epoch %d is stale; current epoch is %d.', $eventEpoch, $currentEpoch));
    }
}
