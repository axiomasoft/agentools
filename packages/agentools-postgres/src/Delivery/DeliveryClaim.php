<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Delivery;

final readonly class DeliveryClaim
{
    public function __construct(public DeliverySubmission $submission, public int $version,
        public int $epoch, public string $owner)
    {
    }

    public function token(): string
    {
        return $this->version.':'.$this->epoch.':'.$this->owner;
    }
}
