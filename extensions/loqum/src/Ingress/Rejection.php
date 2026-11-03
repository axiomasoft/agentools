<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Ingress;

/** Adapter-local refusal. Does not extend the dated Actions or core wire enums. */
final class Rejection extends \DomainException
{
    public function __construct(public readonly string $reason, public readonly ?int $currentRevision = null)
    {
        if (!in_array($reason, ['INTERIM_TRANSCRIPT', 'STALE_MEDIA_EPOCH', 'STALE_CONTEXT_REVISION'], true)) {
            throw new \InvalidArgumentException('Unknown ingress refusal');
        }
        parent::__construct($reason);
    }
}
