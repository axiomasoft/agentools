<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Runtime;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref, SessionId, TurnId};

final readonly class StoredTurn
{
    public function __construct(
        public TurnId $id,
        public SessionId $session,
        public string $sourceKind,
        public string $sourceEventKey,
        public PayloadHash $payloadHash,
        public Ref $inputRef,
    ) {
    }
}
