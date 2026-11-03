<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Budget;

use AgenToolsPlatform\Core\Budget\AdmissionRequest;
use AgenToolsPlatform\Core\Common\Ref;

/** Local immutable owner-operation identity. It is not an invented owner wire format. */
final readonly class Operation
{
    /** @param array<string,mixed> $payload */
    public function __construct(public Ref $id, public AdmissionRequest $request, public Ref $reservation,
        public string $kind, public string $hash, public array $payload) {}
}
