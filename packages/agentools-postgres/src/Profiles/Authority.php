<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Profiles;

use AgenToolsPlatform\Core\Profiles\{BundleManifest, SessionPin, UseBoundary};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Required host port. No permissive authority is supplied by production code. */
interface Authority
{
    /** Current owner permission/purpose, also on idempotent replay and historical inspection. */
    public function authorize(ExecutionContext $context, string $operation): void;

    /** Verify the declared creator, validation and evaluation refs against this exact candidate hash. */
    public function provenance(ExecutionContext $context, BundleManifest $manifest): void;

    /** Current revocation, deployment, emergency, owner permissions and data access over ALL pins. */
    public function use(ExecutionContext $context, BundleManifest $manifest, UseBoundary $boundary): void;

    /** Explicit compatibility validation; returns its durable audit reference, never an implicit approval. */
    public function compatibility(ExecutionContext $context, SessionPin $from, BundleManifest $target): string;
}
