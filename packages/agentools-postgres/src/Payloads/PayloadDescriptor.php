<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref, UtcInstant};

/** Safe technical metadata. It contains neither bytes nor encrypted storage/key handles. */
final readonly class PayloadDescriptor
{
    public function __construct(public Ref $ref, public PayloadHash $contentHash, public string $classification,
        public string $purpose, public UtcInstant $retentionUntil)
    {
        foreach ([$classification, $purpose] as $value) {
            if (preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $value) !== 1) {
                throw new \InvalidArgumentException('Bounded classification and purpose required');
            }
        }
    }
}
