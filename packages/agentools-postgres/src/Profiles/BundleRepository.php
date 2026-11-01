<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Profiles;

use AgenToolsPlatform\Core\Common\{CanonicalJson, ContractViolation, ErrorCode, IntegrityConflict};
use AgenToolsPlatform\Core\Profiles\{BundleManifest, BundleRef, BundleState, BundleValidator, ManifestCodec, UseBoundary};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** Durable publication. External owner checks execute before the short, CAS-fenced SQL transaction. */
final readonly class BundleRepository
{
    private ManifestCodec $codec;

    public function __construct(private RepositoryContext $environment, private BundleValidator $validator,
        private Authority $authority, CanonicalJson $json)
    {
        $this->codec = new ManifestCodec($json);
    }

    /** @phpstan-impure Reads current policy and durable publication state. */
    public function draft(ExecutionContext $context, BundleManifest $manifest, int $expectedRevision = 0): StoredBundle
    {
        $this->authorize($context, $manifest->bundle, 'draft');
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $manifest, $expectedRevision): StoredBundle {
            $key = self::key($manifest->bundle);
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))', ['key' => json_encode($key, JSON_THROW_ON_ERROR)]);
            $before = $db->row('SELECT revision,state FROM ' . $db->table('profile_bundles') . ' WHERE tenant_id=:tenant AND id=:id AND version=:version FOR UPDATE', $key);
            if ($before !== null && Connection::text($before, 'state') !== 'draft') { throw new IntegrityConflict('Published/validated version cannot be edited'); }
            if (($before === null ? 0 : Connection::number($before, 'revision')) !== $expectedRevision) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            $values = $key + ['hash' => $manifest->bundle->hash->value, 'manifest' => $this->codec->encode($manifest)];
            if ($before === null) {
                $db->execute('INSERT INTO ' . $db->table('profile_bundles') . '(tenant_id,id,version,content_hash,manifest,state,revision)
                    VALUES (:tenant,:id,:version,:hash,CAST(:manifest AS jsonb),\'draft\',1)', $values);
            } else {
                $db->execute('UPDATE ' . $db->table('profile_bundles') . ' SET content_hash=:hash,manifest=CAST(:manifest AS jsonb),state=\'draft\',issues=\'[]\',revision=revision+1
                    WHERE tenant_id=:tenant AND id=:id AND version=:version', $values);
            }
            return new StoredBundle($manifest, BundleState::Draft, $expectedRevision + 1, []);
        });
    }

    /** @phpstan-impure Reads current policy and durable publication state. */
    public function validate(ExecutionContext $context, BundleRef $ref, int $expectedRevision): StoredBundle
    {
        $this->authorize($context, $ref, 'validate');
        $before = $this->load($ref);
        if ($before->state !== BundleState::Draft) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        $issues = $this->issues($context, $before->manifest);
        return $this->transition($before, $expectedRevision, $issues === [] ? BundleState::Validated : BundleState::Draft, $issues);
    }

    /** @phpstan-impure Reads current policy and durable publication state. */
    public function publish(ExecutionContext $context, BundleRef $ref, int $expectedRevision): StoredBundle
    {
        $this->authorize($context, $ref, 'publish');
        $before = $this->load($ref);
        // Validation is not a permanent permission or a cached dependency/revocation verdict.
        if ($this->issues($context, $before->manifest) !== []) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        $this->authority->use($context, $before->manifest, UseBoundary::NewSession);
        if ($before->state === BundleState::Published) { return $before; }
        if ($before->state !== BundleState::Validated) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        return $this->transition($before, $expectedRevision, BundleState::Published, []);
    }

    /** Only forward lifecycle transitions; content/provenance survive deprecation and retirement. */
    /** @phpstan-impure Reads current policy and durable publication state. */
    public function advance(ExecutionContext $context, BundleRef $ref, int $expectedRevision, BundleState $target): StoredBundle
    {
        $this->authorize($context, $ref, $target->value);
        $before = $this->load($ref);
        if (!(($before->state === BundleState::Published && $target === BundleState::Deprecated)
            || ($before->state === BundleState::Deprecated && $target === BundleState::Retired))) {
            throw new ContractViolation(ErrorCode::PolicyConflict);
        }
        return $this->transition($before, $expectedRevision, $target, []);
    }

    /** Historical inspection remains available to currently authorized owners after dependency revoke. */
    /** @phpstan-impure Reads current policy and durable publication state. */
    public function inspect(ExecutionContext $context, BundleRef $ref): StoredBundle
    {
        $this->authorize($context, $ref, 'inspect');
        return $this->load($ref);
    }

    /** Call immediately at invocation/action/disclosure, including for an existing session pin. */
    /** @phpstan-impure Reads current policy and durable publication state. */
    public function use(ExecutionContext $context, BundleRef $ref, UseBoundary $boundary): BundleManifest
    {
        $this->authorize($context, $ref, $boundary->value);
        $stored = $this->load($ref);
        $newUse = in_array($boundary, [UseBoundary::NewSession, UseBoundary::Migration], true);
        if ($stored->state !== BundleState::Published && !(!$newUse && $stored->state === BundleState::Deprecated)) {
            throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        }
        $this->validator->assertDependencies($stored->manifest, $context->session->tenant);
        $this->authority->use($context, $stored->manifest, $boundary);
        return $stored->manifest;
    }

    /** @return array{tenant: string, id: string, version: int} */
    public static function key(BundleRef $ref): array
    {
        return ['tenant' => $ref->ref->tenant->value, 'id' => $ref->ref->value, 'version' => $ref->version];
    }

    private function authorize(ExecutionContext $context, BundleRef $ref, string $operation): void
    {
        $context->session->tenant->assertSame($ref->ref->tenant);
        $this->environment->authorize($context);
        $this->authority->authorize($context, $operation);
    }

    private function load(BundleRef $ref): StoredBundle
    {
        $db = $this->environment->db;
        $row = $db->row('SELECT manifest,state,revision,issues FROM ' . $db->table('profile_bundles') . ' WHERE tenant_id=:tenant AND id=:id AND version=:version', self::key($ref))
            ?? throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        $manifest = $this->codec->decode(Connection::text($row, 'manifest'), $ref->ref->tenant);
        $manifest->bundle->ref->assertSame($ref->ref);
        $manifest->bundle->hash->assertSame($ref->hash);
        if ($manifest->bundle->version !== $ref->version) { throw new IntegrityConflict('Bundle version mismatch'); }
        $issues = json_decode(Connection::text($row, 'issues'), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($issues) || !array_is_list($issues)) { throw new IntegrityConflict('Invalid validation issues'); }
        foreach ($issues as $issue) { if (!is_string($issue)) { throw new IntegrityConflict('Invalid issue'); } }
        return new StoredBundle($manifest, BundleState::from(Connection::text($row, 'state')), Connection::number($row, 'revision'), $issues);
    }

    /** @return list<string> */
    private function issues(ExecutionContext $context, BundleManifest $manifest): array
    {
        $issues = $this->validator->issues($manifest);
        try { $this->authority->provenance($context, $manifest); }
        catch (ContractViolation|IntegrityConflict) { $issues[] = 'PROVENANCE_INVALID'; }
        return $issues;
    }

    /** @param list<string> $issues */
    private function transition(StoredBundle $before, int $expected, BundleState $state, array $issues): StoredBundle
    {
        if ($before->revision !== $expected) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $before, $expected, $state, $issues): StoredBundle {
            $changed = $db->execute('UPDATE ' . $db->table('profile_bundles') . ' SET state=:state,issues=CAST(:issues AS jsonb),revision=revision+1
                WHERE tenant_id=:tenant AND id=:id AND version=:version AND revision=:revision AND content_hash=:hash', self::key($before->manifest->bundle) + [
                'state' => $state->value, 'issues' => json_encode($issues, JSON_THROW_ON_ERROR), 'revision' => $expected, 'hash' => $before->manifest->bundle->hash->value]);
            if ($changed->rowCount() !== 1) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            return new StoredBundle($before->manifest, $state, $expected + 1, $issues);
        });
    }
}
