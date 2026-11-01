<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Chatom;

use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Context\ContextAccess;
use AgenToolsPlatform\Core\Context\ContextBlock;
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Core\Security\HostAuthorization;
use AgenToolsPlatform\Postgres\Context\Authority;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Runtime\ContextRevision;

/** Code policy and exact current native versions are rechecked on every repository boundary. */
final readonly class TextContextAuthority implements Authority, ContextAccess, ContextRevision
{
    public function __construct(private Connection $db, private HostAuthorization $authorization,
        private NativeHistory $history, private TextProfile $profile, private CanonicalHasher $hasher) {}

    public function permits(ExecutionContext $context, ContextBlock $block): bool
    {
        if (in_array($block->kind, ['input', 'recent'], true)) {
            $message = $this->history->current($context, new Ref($context->session->tenant, $context->session->value),
                substr($block->source->value, strlen('chatom:message:')), (int) $block->sourceVersion);

            return $block->source->value === $message->source->ref->value
                && $block->modelView === $message->text && $block->trust === 'untrusted';
        }
        $context->assertCurrent($this->authorization);

        return ($block->kind === 'policy' && $block->modelView === $this->profile->policy
                || $block->kind === 'profile' && $block->modelView === $this->profile->system)
            && $block->source->value === $this->profile->bundle->ref->value
            && $block->sourceVersion === $this->profile->version && $block->trust === 'policy';
    }

    public function current(ExecutionContext $context, Ref $snapshot): int
    {
        $context->session->tenant->assertSame($snapshot->tenant);
        $row = $this->db->row('SELECT manifest_json FROM '.$this->db->table('context_snapshots').'
            WHERE tenant_id=:tenant AND id=:id', ['tenant' => $snapshot->tenant->value, 'id' => $snapshot->value]);

        if ($row === null) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }
        $data = json_decode(Connection::text($row, 'manifest_json'), true, 64, JSON_THROW_ON_ERROR);

        if (! is_array($data) || ($data['session_id'] ?? null) !== $context->session->value
            || ($data['bundle'] ?? null) !== $this->profile->bundle->toArray()
            || ($data['policy_revision'] ?? null) !== $context->identity->policyRevision
            || ! is_array($data['sections'] ?? null)) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }
        $versions = [];
        $hashes = [];
        foreach ($data['sections'] as $section) {
            if (! is_array($section)) {
                throw new ContractViolation(ErrorCode::IntegrityConflict);
            }

            if (! in_array($section['kind'] ?? null, ['input', 'recent'], true)) {
                continue;
            }
            $source = $section['source_ref'] ?? null;
            $version = $section['source_version'] ?? null;
            $hash = $section['content_hash'] ?? null;

            if (! is_string($source) || ! str_starts_with($source, 'chatom:message:') || ! is_string($version) || ! is_string($hash)) {
                throw new ContractViolation(ErrorCode::IntegrityConflict);
            }
            $id = substr($source, strlen('chatom:message:'));

            if (isset($versions[$id]) && ($versions[$id] !== $version || $hashes[$id] !== $hash)) {
                throw new ContractViolation(ErrorCode::IntegrityConflict);
            }
            $versions[$id] = $version;
            $hashes[$id] = $hash;
        }

        if ($versions === []) {
            $context->assertCurrent($this->authorization);
        } else {
            // One authorized viewer batch rechecks ALL expected versions after its final owner IO.
            $messages = $this->history->currentMany($context, new Ref($snapshot->tenant, $context->session->value), $versions);
            foreach ($messages as $id => $message) {
                if ($hashes[$id] !== $this->hasher->hash($snapshot->tenant, 'context-model-view', $message->text)->value) {
                    throw new ContractViolation(ErrorCode::StaleContext);
                }
            }
        }

        return 1;
    }

    public function authorize(ExecutionContext $context, ContextSnapshot $snapshot, string $operation): void
    {
        if (! in_array($operation, ['put', 'get'], true)) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }

        if ($operation === 'put') {
            $context->assertCurrent($this->authorization);
        }

        if ($operation === 'get' && $this->current($context, $snapshot->ref) !== $snapshot->revision) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }
    }
}
