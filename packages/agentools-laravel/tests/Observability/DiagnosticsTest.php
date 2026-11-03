<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Tests\Observability;
require_once __DIR__.'/Fixture.php';

use AgenToolsPlatform\Core\Common\{ContractViolation,IntegrityConflict,Ref,RunId,TenantId};
use AgenToolsPlatform\Core\Observability\{Event,Failure,Outcome,Stage};
use AgenToolsPlatform\Core\Security\{EntryPoint,TraceLinks};
use AgenToolsPlatform\Laravel\Observability\{AuditJournal,TraceGraph};
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f=new Fixture(); }
    protected function tearDown(): void { if (isset($this->f)) { $this->f->close(); } }
    private function payload(): string { return 'SYNTHETIC-CHILD-TEXT SYNTHETIC-API-KEY SYNTHETIC-PROVIDER-RESPONSE'; }
    public function testEncryptedSeparateGrantAndMetadataOnlyInspection(): void
    {
        $f=$this->f;$id=$f->ref('diagnostic:one');$ctx=$f->context();$expires=$f->expires();
        try { $f->vault->assertProduction();self::fail('Production rejects diagnostic test ports'); }
        catch (\LogicException $e) { self::assertStringContainsString('Production composition refuses',$e->getMessage()); }
        $receipt=$f->vault->capture($ctx,$id,$f->base->run,$expires,$this->payload());
        self::assertSame($id,$receipt->id);self::assertStringStartsWith('diagnostic-audit:',$receipt->audit->value);self::assertSame([],$f->keys->cache);
        $db=$f->base->f->composition->environment->db;
        $row=$db->row('SELECT ciphertext,nonce FROM '.$db->table('observability_diagnostics'));self::assertNotNull($row);
        self::assertStringNotContainsString('SYNTHETIC',json_encode($row,JSON_THROW_ON_ERROR));
        $payload=$f->vault->read($ctx,$id,$f->base->run);self::assertSame($this->payload(),$payload->reveal());
        self::assertStringNotContainsString('SYNTHETIC',json_encode($payload,JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('SYNTHETIC',print_r($payload,true));
        self::assertContains('diagnostic_capture',$f->grant->permissions);self::assertContains('diagnostic_read',$f->grant->permissions);
        $logs=$db->execute('SELECT document FROM '.$db->table('observability_events'))->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(4,$logs);self::assertStringNotContainsString('SYNTHETIC',json_encode($logs,JSON_THROW_ON_ERROR));
        $inspection=$f->base->operations->status($f->base->op('run_cancel',$f->base->run,0,0));self::assertNull($inspection);
        $graph=(new TraceGraph($f->base->f->composition->environment))->read($ctx,$f->base->run);
        self::assertSame([],$graph);self::assertStringNotContainsString('ciphertext',json_encode($graph,JSON_THROW_ON_ERROR));
        $f->grant->allowed=false;
        try { $f->vault->read($ctx,$id,$f->base->run);self::fail('Inspect access is not diagnostic access'); }
        catch (Failure $e) { self::assertSame('diagnostic_denied',$e->reason); }
        self::assertSame([],$f->keys->cache);
    }
    public function testAuditFailureResolverOutageAndRevokeBeforeRelease(): void
    {
        $f=$this->f;$id=$f->ref('diagnostic:two');$ctx=$f->context();
        $f->audit->fail=true;
        try { $f->vault->capture($ctx,$id,$f->base->run,$f->expires(),$this->payload());self::fail('Mandatory audit failure'); }
        catch (Failure $e) { self::assertSame('audit_unavailable',$e->reason);self::assertNull($e->getPrevious()); }
        self::assertSame(0,$f->keys->calls);self::assertSame(0,$f->base->f->count('observability_diagnostics'));
        $f->audit->fail=false;$f->keys->fail=true;
        try { $f->vault->capture($ctx,$id,$f->base->run,$f->expires(),$this->payload());self::fail('No plaintext fallback'); }
        catch (Failure $e) { self::assertSame('resolver_unavailable',$e->reason);self::assertStringNotContainsString('SYNTHETIC',$e->getMessage()); }
        self::assertSame(0,$f->base->f->count('observability_diagnostics'));self::assertSame([],$f->keys->cache);
        $f->keys->fail=false;$f->vault->capture($ctx,$id,$f->base->run,$f->expires(),$this->payload());
        $f->keys->after=function () use ($f): void { $f->grant->allowed=false; };
        try { $f->vault->read($ctx,$id,$f->base->run);self::fail('Revoked after resolution'); }
        catch (Failure $e) { self::assertSame('diagnostic_denied',$e->reason); }
        self::assertSame([],$f->keys->cache);
    }
    public function testExpiryCrossTenantAndCipherBinding(): void
    {
        $f=$this->f;$id=$f->ref('diagnostic:three');$ctx=$f->context();$expires=$f->expires();
        $f->vault->capture($ctx,$id,$f->base->run,$expires,$this->payload());
        $f->keys->after=function () use ($f): void { $f->clock->shift=90; };
        try { $f->vault->read($ctx,$id,$f->base->run);self::fail('Expiry before release'); }
        catch (Failure $e) { self::assertSame('diagnostic_expired',$e->reason); }
        $f->clock->shift=0;$f->keys->after=null;
        try { $f->vault->capture($ctx,$id,$f->base->run,$expires,'different bytes');self::fail('Same identity different hash'); }
        catch (Failure $e) { self::assertSame('diagnostic_integrity',$e->reason); }
        $calls=$f->keys->calls;
        try { $f->vault->read($ctx,new Ref(new TenantId('foreign'),'diagnostic'),new RunId(new TenantId('foreign'),'run'));self::fail('Tenant binding'); }
        catch (Failure $e) { self::assertSame('diagnostic_unavailable',$e->reason); }
        self::assertSame($calls,$f->keys->calls);
        $db=$f->base->f->composition->environment->db;
        try { $db->execute('UPDATE '.$db->table('observability_diagnostics').' SET nonce=\'tampered\'');self::fail('Immutable ciphertext'); }
        catch (ContractViolation $e) { self::assertSame(\AgenToolsPlatform\Core\Common\ErrorCode::InvalidInput,$e->errorCode); }
        $saved=$db->row('SELECT nonce FROM '.$db->table('observability_diagnostics'));self::assertNotNull($saved);
        $db->execute('DROP TRIGGER retention_guard ON '.$db->table('observability_diagnostics'));
        $db->execute('UPDATE '.$db->table('observability_diagnostics').' SET nonce=:nonce',['nonce'=>base64_encode(str_repeat('z',24))]);
        try { $f->vault->read($ctx,$id,$f->base->run);self::fail('Corrupt authenticated ciphertext'); }
        catch (Failure $e) { self::assertSame('diagnostic_integrity',$e->reason); }
        self::assertIsString($saved['nonce']);
        $db->execute('UPDATE '.$db->table('observability_diagnostics').' SET nonce=:nonce',['nonce'=>$saved['nonce']]);
        \AgenToolsPlatform\Laravel\Observability\Schema::install($db);
        self::assertSame($this->payload(),$f->vault->read($ctx,$id,$f->base->run)->reveal());
    }
    public function testResetFailureQuarantinesDiagnosticScope(): void
    {
        $f=$this->f;$f->keys->resetFail=true;
        try { $f->vault->capture($f->context(),$f->ref('quarantined'),$f->base->run,$f->expires(),$this->payload());self::fail('Reset failure cannot be optional'); }
        catch (Failure $e) { self::assertSame('reset_failed',$e->reason); }
        self::assertSame(1,$f->grant->resets);$calls=$f->keys->calls;$f->keys->resetFail=false;
        try { $f->vault->read($f->context(),$f->ref('quarantined'),$f->base->run);self::fail('Quarantined scope reused'); }
        catch (Failure $e) { self::assertSame('reset_failed',$e->reason); }
        self::assertSame($calls,$f->keys->calls);self::assertSame(2,$f->grant->resets);self::assertSame([],$f->keys->cache);
    }
    public function testRetentionHoldAndTombstone(): void
    {
        $f=$this->f;$id=$f->ref('diagnostic:expiry');$ctx=$f->context();$expiry=$f->expires('+1 second');
        $f->vault->capture($ctx,$id,$f->base->run,$expiry,$this->payload());
        try { $f->vault->prune($ctx,$id,$f->base->run);self::fail('Retention guard'); }
        catch (Failure $e) { self::assertSame('diagnostic_denied',$e->reason); }
        $db=$f->base->f->composition->environment->db;
        $db->execute('UPDATE '.$db->table('observability_diagnostics').' SET hold_ref=\'legal:one\'');
        $deadline=hrtime(true)+3_000_000_000;
        do { $row=$db->row('SELECT expires_at<=clock_timestamp() AS expired FROM '.$db->table('observability_diagnostics'));if (($row['expired']??false)===true) { break; }usleep(1000); }while(hrtime(true)<$deadline);
        self::assertTrue($row['expired']??false);
        try { $f->vault->prune($ctx,$id,$f->base->run);self::fail('Legal hold'); }
        catch (Failure $e) { self::assertSame('diagnostic_denied',$e->reason); }
        $db->execute('UPDATE '.$db->table('observability_diagnostics').' SET hold_ref=NULL');
        $f->vault->prune($ctx,$id,$f->base->run);
        $row=$db->row('SELECT ciphertext,nonce,purged_at,content_hash FROM '.$db->table('observability_diagnostics'));self::assertNotNull($row);
        self::assertNull($row['ciphertext']);self::assertNull($row['nonce']);self::assertNotNull($row['purged_at']);self::assertNotNull($row['content_hash']);
        try { $db->execute('DELETE FROM '.$db->table('observability_diagnostics'));self::fail('Tombstone retained'); }
        catch (ContractViolation $e) { self::assertSame(\AgenToolsPlatform\Core\Common\ErrorCode::InvalidInput,$e->errorCode); }
    }
    public function testAuditIdentityAndTraceGraphUseExistingDurableRefs(): void
    {
        $f=$this->f;[$grant]=$f->base->unknown();$f->base->outputIntent();$env=$f->base->f->composition->environment;$ctx=$f->context();
        $graph=new TraceGraph($env);$links=$graph->read($ctx,$f->base->run,100);self::assertCount(3,$links);
        self::assertSame($grant->action->value,$links[0]->action?->value);self::assertNotNull($links[1]->delivery);self::assertNotNull($links[2]->effect);
        $page=$graph->read($ctx,$f->base->run,1,'action:'.$grant->action->value);self::assertCount(1,$page);self::assertNotNull($page[0]->delivery);
        $journal=new AuditJournal($env);$event=new Event($f->ref('audit:stable'),$ctx,Stage::Effect,Outcome::Unknown,EntryPoint::Raw,$links[2],$env->hasher);
        $journal->append($event);$journal->append($event);
        try { $journal->append(new Event($event->id,$ctx,Stage::Effect,Outcome::Failed,EntryPoint::Raw,$links[2],$env->hasher));self::fail('Audit hash conflict'); }
        catch (IntegrityConflict $e) { self::assertSame('Audit identity conflict',$e->getMessage()); }
        self::assertSame(1,$f->base->f->count('observability_events'));
    }
}
