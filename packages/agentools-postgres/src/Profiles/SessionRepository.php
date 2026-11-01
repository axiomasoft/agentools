<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Profiles;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, InstanceId, IntegrityConflict, PayloadHash, Ref};
use AgenToolsPlatform\Core\Profiles\{BundleManifest, BundleRef, ResourceRef, SessionPin, UseBoundary};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** Versioned profile assignments/pins. Does not rewrite the first-slice Runtime session identity. */
final readonly class SessionRepository
{
    public function __construct(private RepositoryContext $environment, private BundleRepository $bundles, private Authority $authority) {}

    /** Returns assignment revision; command replay returns its original revision under current guards. */
    /** @phpstan-impure Checks current policy and reads or changes durable session state. */
    public function assign(ExecutionContext $context, Ref $command, InstanceId $instance, ResourceRef $definition, BundleRef $target, int $expected): int
    {
        $this->authorize($context, $instance, 'assign');
        $context->session->tenant->assertSame($command->tenant);
        $manifest = $this->bundles->use($context, $target, UseBoundary::NewSession);
        if ($definition->toArray() !== $manifest->definition->toArray()) { throw new IntegrityConflict('Instance/definition compatibility mismatch'); }
        if ($expected < 0 || $expected >= 9007199254740991) { throw new \InvalidArgumentException('Bounded expected revision required'); }
        $hash = $this->environment->hasher->hash($command->tenant, 'profile-assignment', [
            'instance' => $instance->toArray(), 'definition' => $definition->toArray(), 'bundle' => $target->toArray(), 'expected' => $expected,
            'scope' => $context->scopeHash($this->environment->hasher)->value]);
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $command, $hash, $instance, $manifest, $definition, $expected): int {
            $saved = $this->replay($command, $hash);
            if ($saved !== null) { return Connection::number($saved, 'revision'); }
            $this->lockPublished($manifest);
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))', ['key' => json_encode([$instance->tenant->value, 'profile-instance', $instance->value], JSON_THROW_ON_ERROR)]);
            $key = ['tenant' => $instance->tenant->value, 'id' => $instance->value];
            $before = $db->row('SELECT revision,definition FROM ' . $db->table('profile_assignments') . ' WHERE tenant_id=:tenant AND id=:id FOR UPDATE', $key);
            if (($before === null ? 0 : Connection::number($before, 'revision')) !== $expected) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            $definitionJson = json_encode($definition->toArray(), JSON_THROW_ON_ERROR);
            if ($before !== null && json_decode(Connection::text($before, 'definition'), false, 32, JSON_THROW_ON_ERROR) != json_decode($definitionJson, false, 32, JSON_THROW_ON_ERROR)) {
                throw new IntegrityConflict('Instance code definition is immutable');
            }
            $values = $key + ['revision' => $expected + 1, 'bundle' => $manifest->bundle->ref->value,
                'version' => $manifest->bundle->version, 'hash' => $manifest->bundle->hash->value, 'definition' => $definitionJson];
            if ($before === null) {
                $db->execute('INSERT INTO ' . $db->table('profile_assignments') . ' VALUES (:tenant,:id,:revision,:bundle,:version,:hash,CAST(:definition AS jsonb))', $values);
            } else {
                $db->execute('UPDATE ' . $db->table('profile_assignments') . ' SET revision=:revision,bundle_id=:bundle,bundle_version=:version,bundle_hash=:hash,definition=CAST(:definition AS jsonb)
                    WHERE tenant_id=:tenant AND id=:id', $values);
            }
            $this->record($command, $hash, ['revision' => $expected + 1]);
            return $expected + 1;
        });
    }

    /** Initial pin is atomic; a retry first recovers its original pin, regardless of latest assignment. */
    /** @phpstan-impure Checks current policy and reads or changes durable session state. */
    public function pin(ExecutionContext $context, InstanceId $instance): SessionPin
    {
        $this->authorize($context, $instance, 'pin');
        $existing = $this->load($context);
        if ($existing !== null) {
            $existing->instance->assertSame($instance);
            $this->bundles->use($context, $existing->bundle, UseBoundary::Invocation);
            return $existing;
        }
        $db = $this->environment->db;
        $key = ['tenant' => $instance->tenant->value, 'id' => $instance->value];
        $assignment = $db->row('SELECT revision,bundle_id,bundle_version,bundle_hash FROM ' . $db->table('profile_assignments') . ' WHERE tenant_id=:tenant AND id=:id', $key)
            ?? throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        $ref = $this->bundleFrom($context, $assignment);
        $manifest = $this->bundles->use($context, $ref, UseBoundary::NewSession);
        $pin = $db->transaction(function () use ($db, $context, $instance, $assignment, $manifest, $key): SessionPin {
            // Always take the session lock before the assignment lock; migration uses the same order.
            $this->lockSession($context);
            $existing = $this->load($context);
            if ($existing !== null) { $existing->instance->assertSame($instance); return $existing; }
            $now = $db->row('SELECT revision FROM ' . $db->table('profile_assignments') . ' WHERE tenant_id=:tenant AND id=:id FOR SHARE', $key);
            if ($now === null || Connection::number($now, 'revision') !== Connection::number($assignment, 'revision')) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            $this->lockPublished($manifest);
            $pin = new SessionPin($context->session, $instance, 1, $manifest->bundle);
            $this->append($context, $pin, 'initial assignment', 'assignment:' . Connection::number($assignment, 'revision'), 'initial');
            $db->execute('INSERT INTO ' . $db->table('profile_session_heads') . ' VALUES (:tenant,:session,:instance,1)', [
                'tenant' => $instance->tenant->value, 'session' => $context->session->value, 'instance' => $instance->value]);
            return $pin;
        });
        $this->bundles->use($context, $pin->bundle, UseBoundary::Invocation);
        return $pin;
    }

    /** @phpstan-impure Checks current policy and reads or changes durable session state. */
    public function current(ExecutionContext $context, UseBoundary $boundary): SessionPin
    {
        $this->environment->authorize($context);
        $this->authority->authorize($context, $boundary->value);
        $pin = $this->load($context) ?? throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        $this->bundles->use($context, $pin->bundle, $boundary);
        return $pin;
    }

    /** Fence previously compiled work after an explicit migration; use at every execution boundary. */
    /** @phpstan-impure Checks current policy and reads or changes durable session state. */
    public function assertPin(ExecutionContext $context, SessionPin $pin, UseBoundary $boundary): void
    {
        $context->session->assertSame($pin->session);
        $current = $this->current($context, $boundary);
        $current->instance->assertSame($pin->instance);
        if ($current->revision !== $pin->revision || $current->bundle->toArray() !== $pin->bundle->toArray()) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }
    }

    /** A new immutable session version with reason, target, compatibility reference and actor audit. */
    /** @phpstan-impure Checks current policy and reads or changes durable session state. */
    public function migrate(ExecutionContext $context, Ref $command, int $expected, BundleRef $target, string $reason): SessionPin
    {
        $this->environment->authorize($context);
        $this->authority->authorize($context, 'migrate');
        $context->session->tenant->assertSame($command->tenant);
        if ($expected < 1 || $expected >= 9007199254740991 || trim($reason) === '' || strlen($reason) > 1024) {
            throw new \InvalidArgumentException('Explicit source revision and migration reason required');
        }
        $from = $this->load($context, $expected) ?? throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        $manifest = $this->bundles->use($context, $target, UseBoundary::Migration);
        $source = $this->bundles->inspect($context, $from->bundle)->manifest;
        if ($source->definition->toArray() !== $manifest->definition->toArray()) { throw new IntegrityConflict('Session definition migration is unsupported'); }
        $compatibility = $this->authority->compatibility($context, $from, $manifest);
        // Reuse bounded opaque ref validation; the host's compatibility decision is retained verbatim.
        new Ref($command->tenant, $compatibility);
        $hash = $this->environment->hasher->hash($command->tenant, 'profile-session-migration', [
            'session' => $context->session->toArray(), 'expected' => $expected, 'target' => $target->toArray(), 'reason' => $reason,
            'scope' => $context->scopeHash($this->environment->hasher)->value]);
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $context, $command, $hash, $from, $expected, $manifest, $reason, $compatibility): SessionPin {
            $saved = $this->replay($command, $hash);
            if ($saved !== null) {
                $pin = $this->load($context, Connection::number($saved, 'revision')) ?? throw new IntegrityConflict('Missing migration receipt');
                if ($pin->bundle->toArray() !== $manifest->bundle->toArray()) { throw new IntegrityConflict('Migration receipt mismatch'); }
                return $pin;
            }
            $this->lockSession($context);
            $current = $this->load($context) ?? throw new ContractViolation(ErrorCode::CapabilityUnavailable);
            if ($current->revision !== $expected) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            $this->lockPublished($manifest);
            $pin = new SessionPin($context->session, $from->instance, $expected + 1, $manifest->bundle);
            $this->append($context, $pin, $reason, $compatibility, $command->value);
            $db->execute('UPDATE ' . $db->table('profile_session_heads') . ' SET revision=:revision WHERE tenant_id=:tenant AND session_id=:session', [
                'revision' => $pin->revision, 'tenant' => $command->tenant->value, 'session' => $context->session->value]);
            $this->record($command, $hash, ['revision' => $pin->revision]);
            return $pin;
        });
    }

    private function authorize(ExecutionContext $context, InstanceId $instance, string $operation): void
    {
        $context->session->tenant->assertSame($instance->tenant);
        $this->environment->authorize($context);
        $this->authority->authorize($context, $operation);
    }

    private function lockSession(ExecutionContext $context): void
    {
        $this->environment->db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))', [
            'key' => json_encode([$context->session->tenant->value, 'profile-session', $context->session->value], JSON_THROW_ON_ERROR)]);
    }

    /** Prevent deprecation/retirement racing a new assignment/pin/migration SQL commit. */
    private function lockPublished(BundleManifest $manifest): void
    {
        $db = $this->environment->db;
        $row = $db->row('SELECT state,content_hash FROM ' . $db->table('profile_bundles') . ' WHERE tenant_id=:tenant AND id=:id AND version=:version FOR SHARE', BundleRepository::key($manifest->bundle));
        if ($row === null || Connection::text($row, 'state') !== 'published' || Connection::text($row, 'content_hash') !== $manifest->bundle->hash->value) {
            throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        }
    }

    private function load(ExecutionContext $context, ?int $revision = null): ?SessionPin
    {
        $db = $this->environment->db;
        $params = ['tenant' => $context->session->tenant->value, 'session' => $context->session->value];
        $version = 'h.revision';
        if ($revision !== null) { $version = ':revision'; $params['revision'] = $revision; }
        $row = $db->row('SELECT h.instance_id,v.revision,v.bundle_id,v.bundle_version,v.bundle_hash FROM ' . $db->table('profile_session_heads') . ' h
            JOIN ' . $db->table('profile_session_versions') . ' v ON v.tenant_id=h.tenant_id AND v.session_id=h.session_id AND v.revision=' . $version . '
            WHERE h.tenant_id=:tenant AND h.session_id=:session', $params);
        return $row === null ? null : new SessionPin($context->session, new InstanceId($context->session->tenant, Connection::text($row, 'instance_id')),
            Connection::number($row, 'revision'), $this->bundleFrom($context, $row));
    }

    /** @param array<string, mixed> $row */
    private function bundleFrom(ExecutionContext $context, array $row): BundleRef
    {
        return new BundleRef(new Ref($context->session->tenant, Connection::text($row, 'bundle_id')), Connection::number($row, 'bundle_version'), new PayloadHash(Connection::text($row, 'bundle_hash')));
    }

    private function append(ExecutionContext $context, SessionPin $pin, string $reason, string $compatibility, string $command): void
    {
        $db = $this->environment->db;
        $db->execute('INSERT INTO ' . $db->table('profile_session_versions') . '(tenant_id,session_id,revision,bundle_id,bundle_version,bundle_hash,reason,compatibility_ref,actor_ref,command_ref)
            VALUES (:tenant,:session,:revision,:bundle,:version,:hash,:reason,:compatibility,:actor,:command)', [
            'tenant' => $pin->session->tenant->value, 'session' => $pin->session->value, 'revision' => $pin->revision,
            'bundle' => $pin->bundle->ref->value, 'version' => $pin->bundle->version, 'hash' => $pin->bundle->hash->value,
            'reason' => $reason, 'compatibility' => $compatibility, 'actor' => $context->identity->actorRef->value, 'command' => $command]);
    }

    /** @return array<string, mixed>|null */
    private function replay(Ref $command, PayloadHash $hash): ?array
    {
        $db = $this->environment->db;
        $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))', ['key' => json_encode([$command->tenant->value, 'profile-command', $command->value], JSON_THROW_ON_ERROR)]);
        $saved = $db->row('SELECT request_hash,receipt FROM ' . $db->table('profile_commands') . ' WHERE tenant_id=:tenant AND id=:id', ['tenant' => $command->tenant->value, 'id' => $command->value]);
        if ($saved === null) { return null; }
        if (Connection::text($saved, 'request_hash') !== $hash->value) { throw new IntegrityConflict('Profile command identity/hash conflict'); }
        $receipt = json_decode(Connection::text($saved, 'receipt'), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($receipt) || !isset($receipt['revision']) || !is_int($receipt['revision'])) { throw new IntegrityConflict('Invalid command receipt'); }
        return ['revision' => $receipt['revision']];
    }

    /** @param array{revision: int} $receipt */
    private function record(Ref $command, PayloadHash $hash, array $receipt): void
    {
        $db = $this->environment->db;
        $db->execute('INSERT INTO ' . $db->table('profile_commands') . ' VALUES (:tenant,:id,:hash,CAST(:receipt AS jsonb))', [
            'tenant' => $command->tenant->value, 'id' => $command->value, 'hash' => $hash->value, 'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
    }
}
