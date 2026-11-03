<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Observability;

/** No raw sink/resolver/crypto exception or previous exception crosses this boundary. */
final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason,['audit_unavailable','metrics_unavailable','reset_failed','diagnostic_denied',
            'diagnostic_expired','diagnostic_unavailable','resolver_unavailable','diagnostic_integrity'],true)) {
            throw new \InvalidArgumentException('Closed observability failure required');
        }
        parent::__construct($reason);
    }
}
