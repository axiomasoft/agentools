<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Delivery;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** One durable delivery owner. Scanning stored controls never regenerates response content. */
final readonly class DeliveryRepository
{
    /** @param \Closure(ExecutionContext,Ref,string):void $authorizeSource Current host source authority, never an ID grant. */
    public function __construct(private RepositoryContext $environment, private \Closure $authorizeSource)
    {
    }

    public function openSource(ExecutionContext $context, Ref $source, int $revision): void
    {
        $context->session->tenant->assertSame($source->tenant);
        if ($revision<1) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        $this->environment->authorize($context);
        ($this->authorizeSource)($context,$source,'open');
        $db=$this->environment->db;
        $db->transaction(function () use ($db,$source,$revision): void {
            $db->execute('INSERT INTO '.$db->table('source_fences').'(tenant_id,id,watermark) VALUES (:tenant,:id,:revision)
                ON CONFLICT (tenant_id,id) DO NOTHING', ['tenant'=>$source->tenant->value,'id'=>$source->value,'revision'=>$revision]);
            $this->source($source,$revision,true);
        });
    }

    public function submit(ExecutionContext $context, DeliverySubmission $proposal): void
    {
        $context->session->tenant->assertSame($proposal->run->tenant);
        $this->environment->authorize($context);
        ($this->authorizeSource)($context,$proposal->source,'submit');
        if ($context->identity->policyRevision!==$proposal->policyRevision) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }
        $db=$this->environment->db;
        $db->transaction(function () use ($db,$context,$proposal): void {
            $this->source($proposal->source,$proposal->sourceRevision,true);
            $payload=$db->row('SELECT content_hash FROM '.$db->table('payload_refs').' WHERE tenant_id=:tenant AND id=:id
                AND session_id=:session AND purged_at IS NULL AND purge_pending_at IS NULL
                AND retention_until>clock_timestamp() FOR UPDATE', ['tenant'=>$proposal->run->tenant->value,
                    'id'=>$proposal->payload->value,'session'=>$context->session->value]);
            if ($payload===null || Connection::text($payload,'content_hash')!==substr($proposal->hash->value,8)) {
                throw new ContractViolation(ErrorCode::AccessDenied);
            }
            if ($db->row('SELECT r.id FROM '.$db->table('runs').' r JOIN '.$db->table('turns').' t
                ON t.tenant_id=r.tenant_id AND t.id=r.turn_id WHERE r.tenant_id=:tenant AND r.id=:id
                AND t.session_id=:session AND r.status NOT IN (\'cancelled\',\'cancelling\',\'failed\')',
                ['tenant'=>$proposal->run->tenant->value,'id'=>$proposal->run->value,'session'=>$context->session->value])===null) {
                throw new ContractViolation(ErrorCode::AccessDenied);
            }
            $parameters=['tenant'=>$proposal->run->tenant->value,'response'=>$proposal->response->value,
                'run'=>$proposal->run->value,'response_key'=>(string)$proposal->responseVersion,
                'hash'=>substr($proposal->hash->value,8),'payload'=>$proposal->payload->value];
            $db->execute('INSERT INTO '.$db->table('responses').'(tenant_id,id,run_id,response_key,payload_hash,payload_ref_id)
                VALUES (:tenant,:response,:run,:response_key,:hash,:payload) ON CONFLICT DO NOTHING',$parameters);
            $saved=$db->row('SELECT * FROM '.$db->table('responses').' WHERE tenant_id=:tenant AND id=:id',
                ['tenant'=>$parameters['tenant'],'id'=>$parameters['response']]);
            if ($saved===null || Connection::text($saved,'run_id')!==$parameters['run']
                || Connection::text($saved,'response_key')!==$parameters['response_key']
                || Connection::text($saved,'payload_hash')!==$parameters['hash']
                || Connection::text($saved,'payload_ref_id')!==$parameters['payload']) {
                throw new IntegrityConflict('Response identity binding conflict');
            }
            $db->execute('INSERT INTO '.$db->table('delivery_intents').'(tenant_id,id,run_id,response_id,destination,delivery_key,payload_hash)
                VALUES (:tenant,:id,:run,:response,:destination,:key,:hash) ON CONFLICT DO NOTHING',
                ['tenant'=>$parameters['tenant'],'id'=>$proposal->key,'run'=>$parameters['run'],'response'=>$parameters['response'],
                    'destination'=>$proposal->destination->value,'key'=>$proposal->key,'hash'=>$parameters['hash']]);
            $db->execute('INSERT INTO '.$db->table('delivery_controls').'(tenant_id,id,session_id,source_ref,source_revision,response_version,policy_revision,display_hash)
                VALUES (:tenant,:id,:session,:source,:revision,:response_version,:policy,:display_hash) ON CONFLICT DO NOTHING',
                ['tenant'=>$parameters['tenant'],'id'=>$proposal->key,'session'=>$context->session->value,
                    'source'=>$proposal->source->value,'revision'=>$proposal->sourceRevision,
                    'response_version'=>$proposal->responseVersion,'policy'=>$proposal->policyRevision,'display_hash'=>substr($proposal->displayHash->value,8)]);
            $this->matching($this->load($context,$proposal->key),$proposal);
        });
    }

    public function bindInput(ExecutionContext $context, Ref $input, Ref $source, int $revision): void
    {
        $context->session->tenant->assertSame($input->tenant);
        $input->tenant->assertSame($source->tenant);
        if ($revision<1) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        $this->environment->authorize($context);
        ($this->authorizeSource)($context,$source,'admit');
        $db=$this->environment->db;
        $db->transaction(function () use ($db,$context,$input,$source,$revision): void {
            $db->execute('INSERT INTO '.$db->table('source_fences').'(tenant_id,id,watermark) VALUES (:tenant,:id,:revision)
                ON CONFLICT (tenant_id,id) DO NOTHING', ['tenant'=>$source->tenant->value,'id'=>$source->value,'revision'=>$revision]);
            $this->source($source,$revision,true);
            $parameters=['tenant'=>$source->tenant->value,'id'=>$input->value,'session'=>$context->session->value,
                'source'=>$source->value,'revision'=>$revision];
            $db->execute('INSERT INTO '.$db->table('input_source_bindings').'(tenant_id,id,session_id,source_ref,source_revision)
                VALUES (:tenant,:id,:session,:source,:revision) ON CONFLICT DO NOTHING',$parameters);
            $saved=$db->row('SELECT session_id,source_ref,source_revision FROM '.$db->table('input_source_bindings').'
                WHERE tenant_id=:tenant AND id=:id', ['tenant'=>$parameters['tenant'],'id'=>$parameters['id']]);
            if ($saved===null || Connection::text($saved,'session_id')!==$parameters['session']
                || Connection::text($saved,'source_ref')!==$parameters['source'] || Connection::number($saved,'source_revision')!==$revision) {
                throw new IntegrityConflict('Input source binding conflict');
            }
        });
    }

    public function claim(ExecutionContext $context, string $key, int $leaseMilliseconds=30000): ?DeliveryClaim
    {
        if ($leaseMilliseconds<1 || $leaseMilliseconds>3600000) {
            throw new \InvalidArgumentException('Bounded lease required');
        }
        $this->environment->authorize($context);
        $prepared=$this->proposal($context,$this->load($context,$key));
        ($this->authorizeSource)($context,$prepared->source,'claim');
        $db=$this->environment->db;
        return $db->transaction(function () use ($db,$context,$key,$leaseMilliseconds): ?DeliveryClaim {
            $row=$this->load($context,$key);
            $proposal=$this->proposal($context,$row);
            $this->source($proposal->source,$proposal->sourceRevision,true);
            if ($context->identity->policyRevision!==$proposal->policyRevision) {
                throw new ContractViolation(ErrorCode::StaleContext);
            }
            $saved=$db->row('SELECT c.* FROM '.$db->table('delivery_controls').' c JOIN '.$db->table('delivery_intents').' d
                ON d.tenant_id=c.tenant_id AND d.id=c.id JOIN '.$db->table('responses').' r ON r.tenant_id=d.tenant_id AND r.id=d.response_id
                JOIN '.$db->table('payload_refs').' p ON p.tenant_id=r.tenant_id AND p.id=r.payload_ref_id
                WHERE c.tenant_id=:tenant AND c.id=:id AND c.session_id=:session AND NOT c.cancelled
                AND c.state IN (\'pending\',\'unknown\',\'sending\') AND c.attempt_count<c.max_attempts
                AND (c.lease_until IS NULL OR c.lease_until<=clock_timestamp())
                AND p.purged_at IS NULL AND p.purge_pending_at IS NULL AND p.retention_until>clock_timestamp()
                FOR UPDATE OF c SKIP LOCKED', ['tenant'=>$context->session->tenant->value,'id'=>$key,'session'=>$context->session->value]);
            if ($saved===null) {
                return null;
            }
            $owner=bin2hex(random_bytes(32));
            $updated=$db->row('UPDATE '.$db->table('delivery_controls').' SET version=version+1,fencing_epoch=fencing_epoch+1,
                claim_owner=:owner,lease_until=clock_timestamp()+CAST(:lease AS integer)*interval \'1 millisecond\',
                attempt_count=attempt_count+1,state=\'sending\' WHERE tenant_id=:tenant AND id=:id RETURNING *',
                ['owner'=>$owner,'lease'=>$leaseMilliseconds,'tenant'=>$context->session->tenant->value,'id'=>$key])
                ?? throw new ContractViolation(ErrorCode::OptimisticConflict);
            // Claim commits uncertainty before any possible remote effect.
            $db->execute('UPDATE '.$db->table('delivery_intents').' SET version=version+1,outcome=\'unknown\',receipt_ref=NULL
                WHERE tenant_id=:tenant AND id=:id', ['tenant'=>$context->session->tenant->value,'id'=>$key]);
            return new DeliveryClaim($proposal,Connection::number($updated,'version'),Connection::number($updated,'fencing_epoch'),$owner);
        });
    }

    /** Read-only authorization: safe to repeat at a destination's Reauthorize boundary. */
    public function assertCurrent(ExecutionContext $context, DeliverySubmission $proposal, string $token): void
    {
        $context->session->tenant->assertSame($proposal->run->tenant);
        $this->environment->authorize($context);
        ($this->authorizeSource)($context,$proposal->source,'publish');
        $this->assertCommitFence($context, $proposal, $token);
    }

    /**
     * Nonserializable same-operation check, minted only after current host/source authorization.
     * Destination Reauthorize may call it under SQL locks: it reads current owner facts, performs no owner IO,
     * and never rehydrates authority from IDs. The host must also recheck its local binding and native revision.
     * @return \Closure():void
     */
    public function prepareCommit(ExecutionContext $context, DeliverySubmission $proposal, string $token): \Closure
    {
        $this->assertCurrent($context, $proposal, $token);
        return function () use ($context, $proposal, $token): void { $this->assertCommitFence($context, $proposal, $token); };
    }

    private function assertCommitFence(ExecutionContext $context, DeliverySubmission $proposal, string $token): void
    {
        $row=$this->load($context,$proposal->key);
        $this->matching($row,$proposal);
        $this->source($proposal->source,$proposal->sourceRevision,false);
        if ($row['cancelled']!==false || $row['live']!==true || $row['readable']!==true || $row['state']!=='sending'
            || $context->identity->policyRevision!==$proposal->policyRevision
            || !hash_equals(Connection::number($row,'control_version').':'.Connection::number($row,'fencing_epoch').':'.Connection::text($row,'claim_owner'),$token)) {
            throw new ContractViolation(ErrorCode::StaleClaim);
        }
    }

    /** Persist only known destination receipts or an explicit unknown; never infer no effect from an exception. */
    public function settle(ExecutionContext $context, DeliveryClaim $claim, string $outcome, ?Ref $receipt=null): void
    {
        if (!in_array($outcome,['success','failed','unknown'],true) || ($outcome!=='unknown' && $receipt===null)) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        $context->session->tenant->assertSame($claim->submission->run->tenant);
        if ($receipt!==null) {
            $context->session->tenant->assertSame($receipt->tenant);
        }
        $this->environment->authorize($context);
        $db=$this->environment->db;
        $db->transaction(function () use ($db,$context,$claim,$outcome,$receipt): void {
            $row=$this->load($context,$claim->submission->key,true);
            $this->matching($row,$claim->submission);
            $receiptId=hash('sha256',$claim->submission->key.':'.$claim->epoch.':'.$outcome.':'.($receipt->value??''));
            $parameters=['tenant'=>$context->session->tenant->value,'id'=>$receiptId];
            if ($db->row('SELECT id FROM '.$db->table('delivery_receipts').' WHERE tenant_id=:tenant AND id=:id',$parameters)!==null) {
                return;
            }
            if ($row['live']!==true || Connection::number($row,'control_version')!==$claim->version
                || Connection::number($row,'fencing_epoch')!==$claim->epoch || $row['claim_owner']!==$claim->owner) {
                throw new ContractViolation(ErrorCode::StaleClaim);
            }
            $head=$db->row('SELECT coalesce(max(receipt_version),0)+1 AS next FROM '.$db->table('delivery_receipts').'
                WHERE tenant_id=:tenant AND delivery_id=:delivery', ['tenant'=>$parameters['tenant'],'delivery'=>$claim->submission->key]);
            $db->execute('INSERT INTO '.$db->table('delivery_receipts').'(tenant_id,id,delivery_id,receipt_version,payload_hash,outcome,receipt_ref)
                VALUES (:tenant,:id,:delivery,:revision,:hash,:outcome,:receipt)', $parameters+[
                    'delivery'=>$claim->submission->key,'revision'=>Connection::number($head??[],'next'),
                    'hash'=>substr($claim->submission->hash->value,8),'outcome'=>$outcome,'receipt'=>$receipt?->value]);
            $db->execute('UPDATE '.$db->table('delivery_intents').' SET version=version+1,outcome=:outcome,receipt_ref=:receipt
                WHERE tenant_id=:tenant AND id=:id', ['tenant'=>$parameters['tenant'],'id'=>$claim->submission->key,
                    'outcome'=>$outcome,'receipt'=>$receipt?->value]);
            $db->execute('UPDATE '.$db->table('delivery_controls').' SET version=version+1,state=:state,claim_owner=NULL,lease_until=NULL
                WHERE tenant_id=:tenant AND id=:id', ['tenant'=>$parameters['tenant'],'id'=>$claim->submission->key,
                    'state'=>match($outcome){'success'=>'delivered','failed'=>'failed',default=>'unknown'}]);
        });
    }

    /** @return array{state:string,cancelled:bool,receipt:?string,version:int} */
    public function status(ExecutionContext $context, string $key): array
    {
        $this->environment->authorize($context);
        $row=$this->load($context,$key);
        return ['state'=>Connection::text($row,'state'),'cancelled'=>$row['cancelled']===true,
            'receipt'=>is_string($row['receipt_ref'])?$row['receipt_ref']:null,'version'=>Connection::number($row,'control_version')];
    }

    public function cancel(ExecutionContext $context, string $key): void
    {
        $this->environment->authorize($context);
        $proposal=$this->proposal($context,$this->load($context,$key));
        ($this->authorizeSource)($context,$proposal->source,'cancel');
        $db=$this->environment->db;
        $db->transaction(function () use ($db,$context,$key): void {
            $this->load($context,$key,true);
            $db->execute('UPDATE '.$db->table('delivery_controls').' SET version=version+1,cancelled=true,
                state=CASE WHEN state=\'pending\' THEN \'cancelled\' WHEN state=\'sending\' THEN \'unknown\' ELSE state END,
                claim_owner=NULL,lease_until=NULL,fencing_epoch=fencing_epoch+1
                WHERE tenant_id=:tenant AND id=:id AND NOT cancelled', ['tenant'=>$context->session->tenant->value,'id'=>$key]);
        });
    }

    /** @return list<string> A bounded scanner over committed intents, independent of wake delivery. */
    public function pending(ExecutionContext $context, int $limit=100): array
    {
        if ($limit<1 || $limit>1000) {
            throw new \InvalidArgumentException('Bounded scan required');
        }
        $this->environment->authorize($context);
        $db=$this->environment->db;
        $rows=$db->execute('SELECT c.id FROM '.$db->table('delivery_controls').' c JOIN '.$db->table('source_fences').' s
            ON s.tenant_id=c.tenant_id AND s.id=c.source_ref WHERE c.tenant_id=:tenant AND c.session_id=:session
            AND NOT c.cancelled AND NOT s.closed AND c.state IN (\'pending\',\'unknown\',\'sending\')
            AND (c.lease_until IS NULL OR c.lease_until<=clock_timestamp()) AND c.attempt_count<c.max_attempts
            ORDER BY c.created_at,c.id LIMIT '.$limit,
            ['tenant'=>$context->session->tenant->value,'session'=>$context->session->value])->fetchAll(\PDO::FETCH_COLUMN);
        $keys=[];
        foreach ($rows as $key) {
            if (!is_string($key)) {
                throw new ContractViolation(ErrorCode::InvalidInput);
            }
            $keys[]=$key;
        }
        return $keys;
    }

    public function attests(ExecutionContext $context, string $turn, int $responseVersion,
        \AgenToolsPlatform\Core\Common\PayloadHash $displayHash): bool
    {
        $this->environment->authorize($context);
        $db=$this->environment->db;
        $row=$db->row('SELECT d.delivery_key FROM '.$db->table('delivery_controls').' c JOIN '.$db->table('delivery_intents').' d
            ON d.tenant_id=c.tenant_id AND d.id=c.id JOIN '.$db->table('runs').' r ON r.tenant_id=d.tenant_id AND r.id=d.run_id
            WHERE c.tenant_id=:tenant AND c.session_id=:session AND r.turn_id=:turn AND c.response_version=:revision
            AND c.display_hash=:hash AND c.policy_revision=:policy AND NOT c.cancelled',
            ['tenant'=>$context->session->tenant->value,'session'=>$context->session->value,'turn'=>$turn,
                'revision'=>$responseVersion,'hash'=>substr($displayHash->value,8),'policy'=>$context->identity->policyRevision]);
        if ($row===null) {
            return false;
        }
        $saved=$this->load($context,Connection::text($row,'delivery_key'));
        $proposal=$this->proposal($context,$saved);
        ($this->authorizeSource)($context,$proposal->source,'attest');
        $this->source($proposal->source,$proposal->sourceRevision,false);
        return $saved['readable']===true;
    }

    public function closeSource(ExecutionContext $context, Ref $source, int $watermark,
        ?string $operationKey=null, ?\AgenToolsPlatform\Core\Common\PayloadHash $operationHash=null): void
    {
        $context->session->tenant->assertSame($source->tenant);
        if ($watermark<1 || (($operationKey===null)!==($operationHash===null))
            || ($operationKey!==null && ($operationKey==='' || strlen($operationKey)>128))) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        $this->environment->authorize($context);
        ($this->authorizeSource)($context,$source,'close');
        $db=$this->environment->db;
        $db->transaction(function () use ($db,$context,$source,$watermark,$operationKey,$operationHash): void {
            if ($operationKey!==null && $operationHash!==null) {
                $parameters=['tenant'=>$source->tenant->value,'id'=>$operationKey,'hash'=>substr($operationHash->value,8),
                    'source'=>$source->value,'watermark'=>$watermark];
                $db->execute('INSERT INTO '.$db->table('source_invalidation_receipts').'(tenant_id,id,payload_hash,source_ref,watermark)
                    VALUES (:tenant,:id,:hash,:source,:watermark) ON CONFLICT DO NOTHING',$parameters);
                $saved=$db->row('SELECT * FROM '.$db->table('source_invalidation_receipts').' WHERE tenant_id=:tenant AND id=:id',
                    ['tenant'=>$parameters['tenant'],'id'=>$parameters['id']]);
                if ($saved===null || Connection::text($saved,'payload_hash')!==$parameters['hash']
                    || Connection::text($saved,'source_ref')!==$parameters['source'] || Connection::number($saved,'watermark')!==$watermark) {
                    throw new IntegrityConflict('Source invalidation identity conflict');
                }
            }
            $db->execute('INSERT INTO '.$db->table('source_fences').'(tenant_id,id,watermark,closed) VALUES (:tenant,:id,:revision,true)
                ON CONFLICT (tenant_id,id) DO UPDATE SET version=source_fences.version+1,closed=true,
                watermark=greatest(source_fences.watermark,excluded.watermark)',
                ['tenant'=>$source->tenant->value,'id'=>$source->value,'revision'=>$watermark]);
            $db->execute('UPDATE '.$db->table('delivery_controls').' SET version=version+1,cancelled=true,
                state=CASE WHEN state=\'pending\' THEN \'cancelled\' WHEN state=\'sending\' THEN \'unknown\' ELSE state END,
                claim_owner=NULL,lease_until=NULL,fencing_epoch=fencing_epoch+1
                WHERE tenant_id=:tenant AND source_ref=:source AND NOT cancelled',
                ['tenant'=>$context->session->tenant->value,'source'=>$source->value]);
            // Serialize with existing claim/checkpoint CAS. Queued wake signals cannot restart a closed Run.
            $parameters=['tenant'=>$context->session->tenant->value,'source'=>$source->value];
            $bound='EXISTS (SELECT 1 FROM '.$db->table('turns').' t JOIN '.$db->table('input_source_bindings').' b
                ON b.tenant_id=t.tenant_id AND b.id=t.input_ref WHERE t.tenant_id=r.tenant_id AND t.id=r.turn_id AND b.source_ref=:source)';
            $db->execute('UPDATE '.$db->table('runs').' r SET version=version+1,status=\'cancelling\',claim_owner=NULL,lease_until=NULL,fencing_epoch=fencing_epoch+1
                WHERE r.tenant_id=:tenant AND r.status NOT IN (\'completed\',\'failed\',\'cancelled\',\'cancelling\') AND '.$bound,$parameters);
            // Source/output closure stops the next Run step. Terminal cancellation
            // needs a separate audited policy decision after unknown work is reconciled.
        });
    }

    private function source(Ref $source, int $revision, bool $lock): void
    {
        $db=$this->environment->db;
        $row=$db->row('SELECT closed,watermark FROM '.$db->table('source_fences').' WHERE tenant_id=:tenant AND id=:id'.($lock?' FOR UPDATE':''),
            ['tenant'=>$source->tenant->value,'id'=>$source->value]);
        if ($row===null || $row['closed']!==false || Connection::number($row,'watermark')!==$revision) {
            throw new ContractViolation(ErrorCode::Cancelled);
        }
    }

    /** @return array<string,mixed> */
    private function load(ExecutionContext $context, string $key, bool $lock=false): array
    {
        $db=$this->environment->db;
        return $db->row('SELECT d.*,c.version AS control_version,c.session_id,c.source_ref,c.source_revision,c.response_version,
            c.policy_revision,c.display_hash,c.state,c.cancelled,c.claim_owner,c.fencing_epoch,
            (c.lease_until>clock_timestamp()) AS live,r.payload_ref_id,
            (p.purged_at IS NULL AND p.purge_pending_at IS NULL AND p.retention_until>clock_timestamp()) AS readable
            FROM '.$db->table('delivery_intents').' d JOIN '.$db->table('delivery_controls').' c ON c.tenant_id=d.tenant_id AND c.id=d.id
            JOIN '.$db->table('responses').' r ON r.tenant_id=d.tenant_id AND r.id=d.response_id
            JOIN '.$db->table('payload_refs').' p ON p.tenant_id=r.tenant_id AND p.id=r.payload_ref_id
            WHERE d.tenant_id=:tenant AND d.delivery_key=:key AND c.session_id=:session'.($lock?' FOR UPDATE OF c':''),
            ['tenant'=>$context->session->tenant->value,'key'=>$key,'session'=>$context->session->value])
            ?? throw new ContractViolation(ErrorCode::AccessDenied);
    }

    /** @param array<string,mixed> $row */
    private function matching(array $row, DeliverySubmission $proposal): void
    {
        foreach (['run_id'=>$proposal->run->value,'response_id'=>$proposal->response->value,'payload_ref_id'=>$proposal->payload->value,
            'destination'=>$proposal->destination->value,'delivery_key'=>$proposal->key,'payload_hash'=>substr($proposal->hash->value,8),
            'source_ref'=>$proposal->source->value,'policy_revision'=>$proposal->policyRevision,'display_hash'=>substr($proposal->displayHash->value,8)] as $key=>$expected) {
            if (Connection::text($row,$key)!==$expected) {
                throw new IntegrityConflict('Delivery immutable binding conflict');
            }
        }
        if (Connection::number($row,'source_revision')!==$proposal->sourceRevision
            || Connection::number($row,'response_version')!==$proposal->responseVersion) {
            throw new IntegrityConflict('Delivery version binding conflict');
        }
    }

    /** @param array<string,mixed> $row */
    private function proposal(ExecutionContext $context, array $row): DeliverySubmission
    {
        $tenant=$context->session->tenant;
        return new DeliverySubmission(Connection::text($row,'delivery_key'),new \AgenToolsPlatform\Core\Common\RunId($tenant,Connection::text($row,'run_id')),
            new Ref($tenant,Connection::text($row,'response_id')),Connection::number($row,'response_version'),
            new Ref($tenant,Connection::text($row,'payload_ref_id')),new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.Connection::text($row,'payload_hash')),
            new Ref($tenant,Connection::text($row,'destination')),new Ref($tenant,Connection::text($row,'source_ref')),
            Connection::number($row,'source_revision'),Connection::text($row,'policy_revision'),
            new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.Connection::text($row,'display_hash')));
    }
}
