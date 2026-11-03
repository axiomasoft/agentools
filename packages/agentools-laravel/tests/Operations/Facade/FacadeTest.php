<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Tests\Operations\FacadeTests;
require_once __DIR__.'/Fixture.php';

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,IntegrityConflict};
use AgenToolsPlatform\Laravel\Operations\{Facade,Inspector,Journal,Schema};
use PHPUnit\Framework\TestCase;

final class FacadeTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f=new Fixture(); }
    protected function tearDown(): void { if (isset($this->f)) { $this->f->close(); } }
    private function rowsCount(string $table): int { return $this->f->existing->f->count($table); }
    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function item(array $result,int $index=0): array
    {
        $rows=$result['items']; self::assertIsArray($rows); $raw=$rows[$index]; self::assertIsArray($raw);
        $item=[];
        foreach ($raw as $key=>$value) { self::assertIsString($key); $item[$key]=$value; }
        return $item;
    }
    public function testDoctorReadOnlyFakeExpiryAndMissingProbes(): void
    {
        $f=$this->f;
        self::assertSame('ready',$f->service->execute($f->command('doctor'))['status']);
        $f->ports->fake=true; $f->ports->expired=true;
        $service=new Facade($f->existing->f->composition->environment,$f->ports);
        $result=$service->execute($f->command('doctor'));
        self::assertTrue($result['dry_run']); self::assertSame('not_ready',$result['status']);
        $json=json_encode($result,JSON_THROW_ON_ERROR);
        self::assertStringContainsString('expired_or_fake',$json);
        self::assertStringContainsString('production_fake_or_unverified',$json);
        self::assertStringNotContainsString('secret must not escape',$json);
        self::assertSame(0,$this->rowsCount('ops_facade_intents'));
        $f->ports->probeFails=true;
        $result=$service->execute($f->command('doctor'));
        self::assertSame('not_ready',$result['status']);
        self::assertStringContainsString('missing_probe',json_encode($result,JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('credential=',json_encode($result,JSON_THROW_ON_ERROR));
        self::assertSame(0,$f->ports->wakes+$f->ports->deliveries+$f->ports->lookups);
        try { $service->execute($f->command('recover',apply:true)); self::fail('Production fake cannot recover'); }
        catch (\LogicException $error) { self::assertStringContainsString('Production composition refuses',$error->getMessage()); }
        self::assertSame(0,$this->rowsCount('ops_facade_intents'));
    }
    public function testDryRunAuditWaitingReasonsAndCursor(): void
    {
        $f=$this->f; $db=$f->existing->f->composition->environment->db;
        foreach (['waiting_action'=>'awaiting_action','waiting_confirmation'=>'awaiting_confirmation','waiting_external'=>'awaiting_external'] as $state=>$reason) {
            $db->execute('UPDATE '.$db->table('runs').' SET status=:state,version=version+1 WHERE id=:id AND tenant_id=:tenant',
                ['state'=>$state,'id'=>$f->existing->run->value,'tenant'=>$f->existing->run->tenant->value]);
            $result=$f->service->execute($f->command('recover','dry-'.$state));
            self::assertSame($reason,$this->item($result)['reason']); self::assertSame('dry_run',$this->item($result)['operation_result']);
        }
        self::assertSame(3,$this->rowsCount('ops_facade_receipts')); self::assertSame(0,$f->ports->wakes);
        $before=$this->rowsCount('ops_facade_intents');
        $result=$f->service->execute($f->command('inspect-run',limit:1));
        $items=$result['items']; self::assertIsArray($items); self::assertCount(1,$items); self::assertSame($before,$this->rowsCount('ops_facade_intents'));
        $empty=$f->service->execute($f->command('inspect-run',after:'run:'.$f->existing->run->value));
        self::assertSame([],$empty['items']);
    }
    public function testSameRunRecoveryReplayConflictAndUnknownOwner(): void
    {
        $f=$this->f; $c=$f->command('recover',apply:true);
        $first=$f->service->execute($c); self::assertSame('wake_requested',$this->item($first)['operation_result']);
        self::assertSame($first,$f->service->execute($c)); self::assertSame([$f->existing->run->value],$f->ports->keys);
        self::assertSame(1,$f->ports->wakes); self::assertSame(1,$this->rowsCount('runs'));
        try { $f->service->execute($f->command('recover',apply:false)); self::fail('Differing operation input'); }
        catch (IntegrityConflict $error) { self::assertSame('Operation identity conflict',$error->getMessage()); }
        try {
            (new Journal($f->existing->f->composition->environment,$f->ports))->complete($f->command('recover'),['forged'=>'result']);
            self::fail('Receipt helper cannot bypass input binding');
        } catch (IntegrityConflict $error) { self::assertSame('Operation identity conflict',$error->getMessage()); }
        $f->existing->unknown(); $before=$this->rowsCount('example_owner_writes');
        $r=$f->service->execute($f->command('recover','unknown-effect',true));
        $rows=$r['items']; self::assertIsArray($rows);
        self::assertContains('effect_unknown',array_column($rows,'reason'),json_encode($rows,JSON_THROW_ON_ERROR));
        self::assertSame(1,$f->ports->wakes); self::assertSame(1,$f->ports->lookups);
        self::assertSame($before,$this->rowsCount('example_owner_writes'));
        self::assertContains('unknown',array_column($rows,'operation_result'));
        $f->ports->allowed=false; $this->expectException(ContractViolation::class); $f->service->execute($c);
    }
    public function testRevocationBeforeDispatchAndCrossSessionDenied(): void
    {
        $f=$this->f; $f->ports->revokeAt=3;
        try { $f->service->execute($f->command('recover',apply:true)); self::fail('Revoked before wake'); }
        catch (ContractViolation $e) { self::assertSame(ErrorCode::AccessDenied,$e->errorCode); }
        self::assertSame(0,$f->ports->wakes); self::assertSame(0,$this->rowsCount('ops_facade_receipts'));
        $f->ports->revokeAt=null;
        $this->expectException(ContractViolation::class);
        $f->service->execute($f->command('recover','foreign-target',target:'run:other-session'));
    }
    public function testStableDeliveryUnknownAckAndAlreadyDelivered(): void
    {
        $f=$this->f; $ref=$f->existing->outputIntent(); $db=$f->existing->f->composition->environment->db;
        $db->execute('UPDATE '.$db->table('delivery_controls').' SET version=version+1,lease_until=clock_timestamp()-interval \'1 second\' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$ref->tenant->value,'id'=>$ref->value]);
        $f->ports->loseDelivery=true;
        $first=$f->service->execute($f->command('relay','relay-one',true,$ref->value));
        self::assertSame('unknown',$this->item($first)['operation_result']);
        self::assertNull($this->item($first)['receipt_ref']); self::assertSame(1,$f->ports->deliveries);
        $f->ports->loseDelivery=false;
        $second=$f->service->execute($f->command('relay','relay-two',true,$ref->value));
        self::assertSame('delivered',$this->item($second)['operation_result']);
        $third=$f->service->execute($f->command('relay','relay-three',true,$ref->value));
        self::assertSame('delivered',$this->item($third)['reason']); self::assertSame('retained',$this->item($third)['operation_result']);
        self::assertSame([$ref->value,$ref->value],$f->ports->keys);
        self::assertSame(1,$this->rowsCount('delivery_intents')); self::assertSame(0,$f->ports->wakes);
    }
    public function testFailureBetweenCommitsRemainsUnknownAndRetrySameIdentity(): void
    {
        $f=$this->f; $c=$f->command('recover',apply:true); $env=$f->existing->f->composition->environment;
        (new Journal($env,$f->ports))->begin($c); // durable intent, crash before dispatch/receipt
        $status=$f->service->status($c); self::assertNotNull($status); self::assertSame('unknown',$status['state']);
        $fresh=new Fixture($f->existing->f->schema);
        $result=$fresh->service->execute($fresh->command('recover',apply:true));
        self::assertSame('wake_requested',$this->item($result)['operation_result']);
        self::assertSame(1,$this->rowsCount('ops_facade_intents')); self::assertSame(1,$this->rowsCount('ops_facade_receipts'));
        self::assertSame(1,$fresh->ports->wakes);
        Schema::install($env->db); // explicit setup is repeatable
        try { $env->db->execute('DELETE FROM '.$env->db->table('ops_facade_receipts')); self::fail('Immutable receipt'); }
        catch (IntegrityConflict $error) { self::assertStringContainsString('identity conflict',$error->getMessage()); }
    }
    public function testUnknownInvocationAndMissingProposalAreDistinct(): void
    {
        $f=$this->f; $env=$f->existing->f->composition->environment; $db=$env->db;
        $keys=['tenant'=>$f->existing->run->tenant->value,'run'=>$f->existing->run->value];
        $db->execute('INSERT INTO '.$db->table('run_steps').' (tenant_id,id,run_id,step_key) VALUES (:tenant,\'inv-step\',:run,\'inv-step\')',$keys);
        $db->execute('INSERT INTO '.$db->table('invocation_intents').'
            (tenant_id,id,run_id,step_key,invocation_key,request_hash,request_ref,model_ref,admission_ref)
            VALUES (:tenant,\'inv:original\',:run,\'inv-step\',\'stable-inv-key\',:hash,\'request:secret-ref\',\'model:pinned\',\'admission:original\')',
            $keys+['hash'=>str_repeat('a',64)]);
        $db->execute('INSERT INTO '.$db->table('proposal_map').'
            (tenant_id,id,run_id,invocation_id,proposal_key,payload_hash,proposal_ref,action_ref)
            VALUES (:tenant,\'proposal:original\',:run,\'inv:original\',\'proposal-key\',:hash,\'proposal:secret-ref\',\'action:original\')',
            $keys+['hash'=>str_repeat('b',64)]);
        $c=$f->command('recover','invocation-unknown',true);
        $r=$f->service->execute($c); $items=$r['items']; self::assertIsArray($items);
        self::assertContains('invocation_unknown',array_column($items,'reason'));
        self::assertContains('missing_action_submit',array_column($items,'reason'));
        self::assertSame(0,$f->ports->wakes); self::assertSame(2,$f->ports->lookups);
        self::assertContains('stable-inv-key',$f->ports->keys); self::assertContains('action:original',$f->ports->keys);
        self::assertStringNotContainsString('request:secret-ref',json_encode($r,JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('proposal:secret-ref',json_encode($r,JSON_THROW_ON_ERROR));
        self::assertSame(1,$this->rowsCount('invocation_intents')); self::assertSame(0,$this->rowsCount('invocation_results'));
        $late=$f->service->execute($f->command('recover','past-unknown-page',true,limit:1,after:'proposal:zzzz'));
        self::assertSame('reconciliation_required',$this->item($late)['operation_result']);
        self::assertSame(0,$f->ports->wakes);
        $page=$f->service->execute($f->command('inspect-run',limit:1));
        self::assertSame('invocation:inv:original',$page['next_cursor']);
        $next=$f->service->execute($f->command('inspect-run',limit:1,after:'invocation:inv:original'));
        self::assertSame('proposal:original',$this->item($next)['key']);
    }
    public function testDependencyFaultAndCorruptReceiptFailClosed(): void
    {
        $f=$this->f; $env=$f->existing->f->composition->environment;
        $c=$f->command('recover'); $f->service->execute($c);
        $env->db->execute('DROP TRIGGER append_only ON '.$env->db->table('ops_facade_receipts'));
        $env->db->execute('UPDATE '.$env->db->table('ops_facade_receipts').' SET result_hash=\'corrupt\'');
        try { $f->service->execute($c); self::fail('Corrupt receipt cannot be accepted'); }
        catch (IntegrityConflict $e) { self::assertSame('Operational receipt hash conflict',$e->getMessage()); }
        $env->db->execute('DROP TABLE '.$env->db->table('platform_migrations'));
        $r=$f->service->execute($f->command('doctor'));
        self::assertSame('not_ready',$r['status']);
        self::assertStringContainsString('dependency_unavailable',json_encode($r,JSON_THROW_ON_ERROR));
        self::assertSame(0,$f->ports->wakes);
    }
    public function testCliTrustedHandleDryRunApplyAndRedactedFault(): void
    {
        $f=$this->f;
        $source=new class($f) implements \AgenToolsPlatform\Laravel\Operations\RequestSource {
            public function __construct(private Fixture $f) {}
            public function command(string $handle,string $verb,bool $apply,int $limit,?string $after): \AgenToolsPlatform\Laravel\Operations\Command
            {
                if ($handle!=='trusted') { throw new \RuntimeException('raw credential from resolver'); }
                return $this->f->command($verb,$apply?'cli-apply':'cli-dry',$apply,limit:$limit,after:$after);
            }
        };
        $container=new \Illuminate\Foundation\Application(dirname(__DIR__,5));
        $container->instance('env','testing');
        $container->instance('config',new \Illuminate\Config\Repository());
        $container->instance(\AgenToolsPlatform\Laravel\Operations\Facade::class,$f->service);
        $container->instance(\AgenToolsPlatform\Laravel\Operations\RequestSource::class,$source);
        $command=new \AgenToolsPlatform\Laravel\Console\Recover(); $command->setLaravel($container);
        $app=new \Symfony\Component\Console\Application(); $app->add($command);
        $tester=new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0,$tester->execute(['operation_ref'=>'trusted']));
        self::assertStringContainsString('"dry_run":true',$tester->getDisplay()); self::assertSame(0,$f->ports->wakes);
        self::assertSame(0,$tester->execute(['operation_ref'=>'trusted','--apply'=>true])); self::assertSame(1,$f->ports->wakes);
        self::assertSame(1,$tester->execute(['operation_ref'=>'forged']));
        self::assertStringContainsString('operation_unresolved',$tester->getDisplay());
        self::assertStringNotContainsString('raw credential',$tester->getDisplay());
        self::assertSame(2,$tester->execute(['operation_ref'=>'trusted','--limit'=>'101']));
    }
    public function testOwnerLookupRecordsOriginalEffectWithoutReplay(): void
    {
        $f=$this->f; [$grant,$ledger,$owner,$service]=$f->existing->unknown();
        $f->ports->effectLookup=new \AgenToolsPlatform\Laravel\Operations\EffectLookup($ledger->request(),$service);
        $before=$this->rowsCount('example_owner_writes');
        $observed=(new Inspector($f->existing->f->composition->environment,$f->ports))->inspect($f->command('inspect-run'));
        $effect=$observed[0];
        try { $f->ports->effectLookup->lookup($f->command('reconcile'),$effect,static function (): void {}); self::fail('Dry-run cannot record owner outcome'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::PolicyConflict,$error->errorCode); }
        $bad=new \AgenToolsPlatform\Laravel\Operations\Observation($effect->kind,$effect->key,$effect->state,$effect->reason,$effect->route,
            $effect->version,$effect->epoch,$effect->ageSeconds,$effect->requestHash,'different-owner-key');
        try { $f->ports->effectLookup->lookup($f->command('reconcile',apply:true),$bad,static function (): void {}); self::fail('Owner key binding'); }
        catch (IntegrityConflict $error) { self::assertSame('Effect lookup identity conflict',$error->getMessage()); }
        $result=$f->service->execute($f->command('reconcile','owner-lookup',true));
        self::assertSame('owner_recorded',$this->item($result)['operation_result']);
        self::assertNotNull($this->item($result)['receipt_ref']);
        self::assertSame($before,$this->rowsCount('example_owner_writes'));
        $c=$f->existing->f->composition;
        self::assertSame('succeeded',$c->actions->status($grant->action,$c->context())->outcome()?->value);
        self::assertSame(0,$f->ports->wakes);
    }
    public function testPruneExpiredPayloadKeepsTombstoneWithoutDereference(): void
    {
        $f=$this->f; $env=$f->existing->f->composition->environment; $context=$f->existing->f->composition->context();
        $env->db->execute('INSERT INTO '.$env->db->table('payload_refs').'
            (tenant_id,id,session_id,binding_hash,content_hash,encrypted_ref,encryption_key_ref,classification,purpose,retention_until)
            VALUES (:tenant,\'expired:payload\',:session,:binding,:hash,\'cipher:secret\',\'key:secret\',\'private\',:purpose,clock_timestamp()-interval \'1 second\')',
            ['tenant'=>$context->session->tenant->value,'session'=>$context->session->value,'purpose'=>$context->purpose,
                'binding'=>str_repeat('b',64),'hash'=>str_repeat('a',64)]);
        $policy=new class implements \AgenToolsPlatform\Postgres\Payloads\PayloadPolicy {
            public function authorize(\AgenToolsPlatform\Core\Security\ExecutionContext $context,\AgenToolsPlatform\Postgres\Payloads\PayloadDescriptor $payload,string $operation): \AgenToolsPlatform\Core\Common\Ref
            { return new \AgenToolsPlatform\Core\Common\Ref($payload->ref->tenant,'retention:verified'); }
        };
        $store=new class implements \AgenToolsPlatform\Postgres\Payloads\EncryptedPayloadStore {
            public int $deletes=0; public int $reads=0;
            public function read(\AgenToolsPlatform\Core\Security\ExecutionContext $context,\AgenToolsPlatform\Postgres\Payloads\PayloadHandle $handle): string
            { ++$this->reads; throw new \LogicException('No content dereference'); }
            public function supportsIdempotentPurge(): bool { return true; }
            public function purge(\AgenToolsPlatform\Core\Security\ExecutionContext $context,\AgenToolsPlatform\Postgres\Payloads\PayloadHandle $handle,string $operationKey): void { ++$this->deletes; }
            public function reset(): void {}
        };
        $payloads=new \AgenToolsPlatform\Postgres\Payloads\PayloadRepository($env,$policy,$store);
        $service=new Facade($env,$f->ports,payloads:$payloads,production:false);
        $dry=$service->execute($f->command('prune','expired-dry',target:'expired:payload'));
        self::assertSame('expired',$this->item($dry)['reason']); self::assertSame(0,$store->deletes);
        $service->execute($f->command('prune','expired-apply',true,'expired:payload'));
        self::assertSame(1,$store->deletes); self::assertSame(0,$store->reads);
        $row=$env->db->row('SELECT encrypted_ref,encryption_key_ref,purged_at FROM '.$env->db->table('payload_refs').' WHERE id=\'expired:payload\'');
        self::assertNotNull($row); self::assertNull($row['encrypted_ref']); self::assertNull($row['encryption_key_ref']); self::assertNotNull($row['purged_at']);
        $again=$service->execute($f->command('prune','expired-retry',true,'expired:payload'));
        self::assertSame('tombstone',$this->item($again)['reason']); self::assertSame(1,$store->deletes);
    }
    public function testPruneRetainsRetentionAndLegalHold(): void
    {
        $f=$this->f; $f->existing->outputIntent(); $env=$f->existing->f->composition->environment;
        $c=$f->command('prune',target:'output:payload');
        $r=$f->service->execute($c); self::assertSame('unresolved_dependency',$this->item($r)['reason']);
        $env->db->execute('INSERT INTO '.$env->db->table('payload_holds').' (tenant_id,id,payload_id,authority_ref)
            VALUES (:tenant,\'legal:one\',\'output:payload\',\'authority:legal\')',['tenant'=>$c->id->tenant->value]);
        $r=$f->service->execute($f->command('prune','held',true,'output:payload'));
        self::assertSame('legal_hold',$this->item($r)['reason']); self::assertSame('retained',$this->item($r)['operation_result']);
        self::assertSame(0,$f->ports->deliveries);
    }
}
