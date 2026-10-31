<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Runtime;

use AgenToolsPlatform\Core\Common\{InstanceId, IntegrityConflict, PayloadHash, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;

final readonly class SessionRepository
{
    public function __construct(private RepositoryContext $environment)
    {
    }

    public function bind(ExecutionContext $context, SessionBinding $binding): SessionBinding
    {
        $context->session->assertSame($binding->session);
        $this->environment->authorize($context);
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $context, $binding): SessionBinding {
            $instance = ['tenant' => $binding->instance->tenant->value, 'id' => $binding->instance->value,
                'config' => $binding->configRef->value, 'config_hash' => substr($binding->configHash->value, 8),
                'registry_hash' => substr($binding->registryHash->value, 8)];
            $db->execute('INSERT INTO ' . $db->table('instances') . '(tenant_id,id,config_ref,config_hash,registry_hash)
                VALUES (:tenant,:id,:config,:config_hash,:registry_hash) ON CONFLICT (tenant_id,id) DO NOTHING', $instance);
            $saved = $db->row('SELECT config_ref,config_hash,registry_hash FROM ' . $db->table('instances') . '
                WHERE tenant_id=:tenant AND id=:id', ['tenant' => $instance['tenant'], 'id' => $instance['id']]);
            if ($saved === null || Connection::text($saved, 'config_ref') !== $instance['config']
                || Connection::text($saved, 'config_hash') !== $instance['config_hash']
                || Connection::text($saved, 'registry_hash') !== $instance['registry_hash']) {
                throw new IntegrityConflict('Instance configuration pin conflict');
            }
            $db->execute('INSERT INTO ' . $db->table('sessions') . '(tenant_id,id,instance_id,bundle_ref,bundle_hash,history_ref)
                VALUES (:tenant,:id,:instance,:bundle,:hash,:history) ON CONFLICT (tenant_id,id) DO NOTHING', [
                'tenant' => $binding->session->tenant->value, 'id' => $binding->session->value,
                'instance' => $binding->instance->value, 'bundle' => $binding->bundleRef->value,
                'hash' => substr($binding->bundleHash->value, 8), 'history' => $binding->historyRef?->value]);
            $result = $this->load($context);
            if ($result === null || $result->instance->value !== $binding->instance->value
                || $result->bundleRef->value !== $binding->bundleRef->value
                || $result->bundleHash->value !== $binding->bundleHash->value
                || $result->historyRef?->value !== $binding->historyRef?->value) {
                throw new IntegrityConflict('Session pin conflict');
            }
            return $result;
        });
    }

    public function get(ExecutionContext $context): ?SessionBinding
    {
        $this->environment->authorize($context);
        return $this->load($context);
    }

    private function load(ExecutionContext $context): ?SessionBinding
    {
        $db = $this->environment->db;
        $row = $db->row('SELECT s.instance_id,s.bundle_ref,s.bundle_hash,s.history_ref,i.config_ref,i.config_hash,i.registry_hash
            FROM ' . $db->table('sessions') . ' s JOIN ' . $db->table('instances') . ' i ON i.tenant_id=s.tenant_id AND i.id=s.instance_id
            WHERE s.tenant_id=:tenant AND s.id=:session', ['tenant' => $context->session->tenant->value, 'session' => $context->session->value]);
        if ($row === null) {
            return null;
        }
        $tenant = $context->session->tenant;
        return new SessionBinding($context->session, new InstanceId($tenant, Connection::text($row, 'instance_id')),
            new Ref($tenant, Connection::text($row, 'config_ref')), new PayloadHash('sha-256:' . Connection::text($row, 'config_hash')),
            new PayloadHash('sha-256:' . Connection::text($row, 'registry_hash')), new Ref($tenant, Connection::text($row, 'bundle_ref')),
            new PayloadHash('sha-256:' . Connection::text($row, 'bundle_hash')),
            $row['history_ref'] === null ? null : new Ref($tenant, Connection::text($row, 'history_ref')));
    }
}
