<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

/** An immutable historic result for a Run, never an authorization for a later disclosure. */
final readonly class EvidenceSet
{
    /** @param list<array<string,mixed>> $fragments */
    public function __construct(public string $tenant, public string $runRef, public string $queryHash,
        public string $status, public string $code, public array $fragments,
        public int $checkedAt, public int $expiresAt)
    {
        if (!in_array($status, ['success', 'empty', 'denied', 'unknown', 'unavailable', 'incompatible'], true)
            || !array_is_list($fragments) || ($status !== 'success' && $fragments !== [])
            || ($status === 'success' && $fragments === [])) {
            throw new \InvalidArgumentException('Evidence and outcome must agree');
        }
    }

    /** @return array<string,mixed> */
    public function toolView(OwnerViews $views): array
    {
        if ($views->tenant !== $this->tenant) { throw new \DomainException('Cross-tenant owner result ref'); }
        $compact = array_map(static fn(array $fragment): array => [
            'text' => $fragment['text'], 'citation' => $fragment['citation'],
        ], $this->fragments);
        return ['tenant' => $this->tenant, 'run_ref' => $this->runRef, 'query_hash' => $this->queryHash,
            'status' => $this->status, 'code' => $this->code, 'trust_class' => 'untrusted',
            'raw_result_ref' => $views->raw, 'user_view_ref' => $views->user, 'audit_view_ref' => $views->audit,
            'model_view' => json_encode(['status' => $this->status, 'code' => $this->code, 'evidence' => $compact], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'evidence' => $this->fragments, 'freshness' => ['checked_at' => $this->checkedAt, 'expires_at' => $this->expiresAt],
            'memory_activation' => false];
    }
}
