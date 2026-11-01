<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Rendered bytes are transient; only the manifest/hash is persisted by default. */
final readonly class CompiledContext
{
    public function __construct(public ContextSnapshot $snapshot, public string $rendered, public string $manifestJson) {}

    /** Verify a compiler artifact before durable storage; construction alone grants no authority. */
    public function assertIntegrity(ExecutionContext $context, CanonicalHasher $hasher): void
    {
        $this->snapshot->assertScope($context, $hasher);
        $manifest = json_decode($this->manifestJson, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['snapshot_id'] ?? null) !== $this->snapshot->ref->value
            || ($manifest['immutable_content_hash'] ?? null) !== $this->snapshot->contentHash->value
            || ($manifest['scope_hash'] ?? null) !== $this->snapshot->scopeHash->value
            || ($manifest['tenant_id'] ?? null) !== $context->identity->tenant->value
            || ($manifest['session_id'] ?? null) !== $context->session->value) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict);
        }
        unset($manifest['snapshot_id'], $manifest['immutable_content_hash']);
        $this->snapshot->contentHash->assertSame($hasher->hash($context->identity->tenant, 'context-snapshot',
            ['manifest' => $manifest, 'rendered' => $this->rendered]));
    }
}
