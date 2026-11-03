<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Operations;

/** Diagnostic classification, deliberately separate from the dated ActionStatus wire enum. */
final readonly class Observation
{
    public function __construct(public string $kind, public string $key, public string $state,
        public string $reason, public string $route, public int $version, public int $epoch,
        public int $ageSeconds, public ?string $requestHash = null, public ?string $ownerKey = null, public ?string $deadlineAt = null, public ?string $escalation = null) {}
    /** @return array<string,string|int|null> */
    public function metadata(): array
    {
        return ['kind'=>$this->kind,'key'=>$this->key,'state'=>$this->state,'reason'=>$this->reason,
            'route'=>$this->route,'version'=>$this->version,'epoch'=>$this->epoch,'age_seconds'=>$this->ageSeconds,
            'request_hash'=>$this->requestHash,'owner_key'=>$this->ownerKey,'deadline_at'=>$this->deadlineAt,'escalation'=>$this->escalation];
    }
}
