<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Context;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Context\{CompiledContext, ContextSnapshot};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** Immutable insert/replay. Only manifest JSON is durable; rendered bytes never enter SQL. */
final readonly class SnapshotRepository
{
    public function __construct(private RepositoryContext $environment,private Authority $authority) {}

    public function put(ExecutionContext $context,CompiledContext $compiled): ContextSnapshot
    {
        $this->authorize($context,$compiled->snapshot,'put'); $compiled->assertIntegrity($context,$this->environment->hasher);
        $snapshot=$compiled->snapshot;
        $manifestHash=$this->manifestHash($context,$compiled->manifestJson);
        $db=$this->environment->db;
        return $db->transaction(function() use($db,$snapshot,$compiled,$manifestHash): ContextSnapshot {
            $values=['tenant'=>$snapshot->ref->tenant->value,'id'=>$snapshot->ref->value,'revision'=>$snapshot->revision,
                'content'=>$snapshot->contentHash->value,'scope'=>$snapshot->scopeHash->value,'manifest'=>$compiled->manifestJson,'manifest_hash'=>$manifestHash];
            $db->execute('INSERT INTO '.$db->table('context_snapshots').' (tenant_id,id,revision,content_hash,scope_hash,manifest_json,manifest_hash)
                VALUES (:tenant,:id,:revision,:content,:scope,:manifest,:manifest_hash) ON CONFLICT (tenant_id,id) DO NOTHING',$values);
            $row=$db->row('SELECT revision,content_hash,scope_hash,manifest_hash FROM '.$db->table('context_snapshots').' WHERE tenant_id=:tenant AND id=:id',
                ['tenant'=>$values['tenant'],'id'=>$values['id']]);
            if ($row === null || Connection::number($row,'revision') !== $snapshot->revision || Connection::text($row,'content_hash') !== $values['content']
                || Connection::text($row,'scope_hash') !== $values['scope'] || Connection::text($row,'manifest_hash') !== $manifestHash) {
                throw new ContractViolation(ErrorCode::IntegrityConflict);
            }
            return $snapshot;
        });
    }

    public function get(ExecutionContext $context,ContextSnapshot $snapshot): string
    {
        $this->authorize($context,$snapshot,'get'); $db=$this->environment->db;
        $row=$db->row('SELECT * FROM '.$db->table('context_snapshots').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$snapshot->ref->tenant->value,'id'=>$snapshot->ref->value]) ?? throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        $manifest=Connection::text($row,'manifest_json');
        if (Connection::number($row,'revision') !== $snapshot->revision || Connection::text($row,'content_hash') !== $snapshot->contentHash->value
            || Connection::text($row,'scope_hash') !== $snapshot->scopeHash->value || Connection::text($row,'manifest_hash') !== $this->manifestHash($context,$manifest)) {
            throw new ContractViolation(ErrorCode::IntegrityConflict);
        }
        return $manifest;
    }

    private function authorize(ExecutionContext $context,ContextSnapshot $snapshot,string $operation): void
    {
        $this->environment->authorize($context); $snapshot->assertScope($context,$this->environment->hasher);
        $this->authority->authorize($context,$snapshot,$operation);
    }
    private function manifestHash(ExecutionContext $context,string $manifest): string
    {
        return $this->environment->hasher->hash($context->identity->tenant,'context-manifest',json_decode($manifest,true,64,JSON_THROW_ON_ERROR))->value;
    }
}
