<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Usage;

/** Adapter contract, not a claimed ControLLM SDK response. Status must bind the exact content. */
final readonly class ExportStatus
{
    public function __construct(public string $state, public string $payloadHash, public ?string $acknowledgement = null)
    {
        if (!in_array($state,['not_found','exported','unknown'],true) || ($state === 'exported' && ($acknowledgement === null || $acknowledgement === ''))) {
            throw new \InvalidArgumentException('Explicit metering owner status required');
        }
    }
}
