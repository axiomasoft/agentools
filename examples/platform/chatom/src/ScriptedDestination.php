<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Chatom;

use AgenToolsPlatform\ChatomBridge\{Destination, Receipt};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, Outcome, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Delivery\DeliverySubmission;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** Isolated fixture OWNER of the transcript; not the real Chatom driver or a production fallback. */
final class ScriptedDestination implements Destination
{
    public bool $unavailable = false;
    public bool $loseAck = false;
    public ?\Closure $beforeCommit = null;
    public ?\Closure $afterCommit = null;
    public ?\Closure $afterLookup = null;
    public function __construct(private Connection $owner, private RepositoryContext $runtime) {}
    public function authorize(ExecutionContext $context, DeliverySubmission $submission): void
    {
        $this->runtime->db->assertIdle();
        $context->session->tenant->assertSame($submission->destination->tenant);
        $row = $this->owner->row('SELECT allowed,visible,held,relay FROM '.$this->owner->table('fixture_membership').'
            WHERE tenant_id=:tenant AND destination=:destination',
            ['tenant'=>$context->session->tenant->value,'destination'=>$submission->destination->value]);
        if ($row === null || $row['allowed'] !== true || $row['visible'] !== true || $row['held'] !== false || $row['relay'] !== 'platform') {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }
    public function lookup(ExecutionContext $context, DeliverySubmission $submission): Receipt
    {
        $this->authorize($context, $submission);
        if ($this->unavailable) { throw new \RuntimeException('Scripted destination unavailable'); }
        $row=$this->owner->row('SELECT payload_hash,message_ref FROM '.$this->owner->table('fixture_messages').'
            WHERE tenant_id=:tenant AND destination=:destination AND delivery_key=:key',
            ['tenant'=>$context->session->tenant->value,'destination'=>$submission->destination->value,'key'=>$submission->key]);
        if ($row !== null && $row['payload_hash'] !== $submission->hash->value) { throw new IntegrityConflict('DELIVERY_PAYLOAD_CONFLICT'); }
        $receipt=new Receipt($submission->destination,$submission->key,$submission->hash,
            $row === null ? Outcome::Unknown : Outcome::Succeeded,
            $row === null ? null : new Ref($context->session->tenant,Connection::text($row,'message_ref')));
        if ($this->afterLookup !== null) { ($this->afterLookup)(); }
        return $receipt;
    }
    public function send(ExecutionContext $context, DeliverySubmission $submission, string $payload, \Closure $current): Receipt
    {
        $this->authorize($context,$submission);
        if ($this->unavailable) { throw new \RuntimeException('Scripted destination unavailable'); }
        if ($this->beforeCommit !== null) { ($this->beforeCommit)(); }
        $receipt=$this->owner->transaction(function () use ($context,$submission,$payload,$current): Receipt {
            // Owner permission row is locked in this mutation; pre-enqueue permission is insufficient.
            $this->owner->row('SELECT allowed FROM '.$this->owner->table('fixture_membership').'
                WHERE tenant_id=:tenant AND destination=:destination FOR SHARE',
                ['tenant'=>$context->session->tenant->value,'destination'=>$submission->destination->value]);
            $this->authorize($context,$submission); $current();
            $message='chatom:message:'.hash('sha256',$context->session->tenant->value.':'.$submission->key);
            $this->owner->execute('INSERT INTO '.$this->owner->table('fixture_messages').'
                (tenant_id,destination,delivery_key,payload_hash,message_ref,body) VALUES (:tenant,:destination,:key,:hash,:message,:body)
                ON CONFLICT DO NOTHING', ['tenant'=>$context->session->tenant->value,'destination'=>$submission->destination->value,
                    'key'=>$submission->key,'hash'=>$submission->hash->value,'message'=>$message,'body'=>$payload]);
            return $this->lookup($context,$submission);
        });
        if ($this->afterCommit !== null) { ($this->afterCommit)(); }
        if ($this->loseAck) { throw new \RuntimeException('Scripted lost destination ACK'); }
        return $receipt;
    }
}
