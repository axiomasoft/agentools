<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Tests\Delivery;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref, UtcInstant};
use AgenToolsPlatform\Core\Security\{DisclosureMode, DisclosurePolicy};
use AgenToolsPlatform\Loqum\Delivery\{Attempt, Coordinator, Ledger, PlayoutReceipt, Response, Schema, UiCommand};
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class DeliveryTest extends TestCase
{
    private IsolatedDatabase $db;
    private string $schema;
    private Fixture $f;
    protected function setUp(): void
    {
        $this->db=new IsolatedDatabase();$this->schema='p3_'.bin2hex(random_bytes(12));$this->db->createSchema($this->schema);
        (new Migrator($this->db->pdo,$this->schema))->migrate();$this->f=new Fixture($this->db->pdo,$this->schema);
        Schema::install($this->f->pg->environment->db);
    }
    protected function tearDown(): void
    {
        if (isset($this->db,$this->schema)) { if ($this->db->pdo->inTransaction()) { $this->db->pdo->rollBack(); }$this->db->dropSchema($this->schema); }
    }
    private function denies(callable $fn,string $type=ContractViolation::class): void
    {
        try { $fn(); } catch (\Throwable $e) { self::assertInstanceOf($type,$e);return; }self::fail('Expected rejection');
    }
    private function command(string $id='command:1',string $type='highlight',string $expiry='2026-11-03T11:00:00Z',int $revision=1): UiCommand
    {
        $s=$this->f->segment();return new UiCommand($this->f->ref($id),$type,1,'voice-ui/v1',$revision,new UtcInstant($expiry),
            $s->key,$this->f->ref('ui-payload'),new PayloadHash('sha-256:'.str_repeat('a',64)),$this->f->pg->hasher);
    }
    public function testIndependentPortions(): void
    {
        $f=$this->f;$s=$f->segment();$f->delivery->register($s);
        self::assertSame([], $f->delivery->history($f->response));self::assertFalse($f->ledger->state($s)['synthesized']);
        $f->delivery->display($s);self::assertSame($s->displayBytes,$f->ledger->state($s)['displayed']);
        self::assertSame([], $f->delivery->history($f->response));
        $f->evidence='server_estimate';$f->delivery->send($s);
        self::assertSame('unknown',$f->ledger->state($s)['state']);self::assertSame(0,$f->ledger->state($s)['confirmed']);
        self::assertTrue($f->ledger->state($s)['synthesized']);self::assertSame([], $f->delivery->history($f->response));
        $a=new Attempt($s,0,1,$f->pg->hasher);
        $f->incoming=new PlayoutReceipt($f->ref('client:partial'),$f->response->response,1,$s->key,$a->key,1,3,'interrupted','client_ack');
        $f->delivery->receive($s,'host:receipt');$history=$f->delivery->history($f->response);
        self::assertSame(3,$history[0]['through_bytes']);self::assertSame('one',substr('one two',0,$history[0]['through_bytes']));
        self::assertNull($history[0]['understanding']);self::assertSame($s->displayBytes,$f->ledger->state($s)['displayed']);
    }
    public function testSuffixRetry(): void
    {
        $f=$this->f;$s=$f->segment();$f->delivery->register($s);$f->nextOffset=3;$f->delivery->send($s);
        $f->nextOffset=null;$f->delivery->send($s);$f->delivery->send($s);
        self::assertSame([0,3],array_column($f->plays,1));self::assertSame([0,3],array_column($f->syntheses,1));
        self::assertSame(7,$f->delivery->history($f->response)[0]['through_bytes']);
        self::assertSame((new Attempt($s,3,1,$f->pg->hasher))->key->value,(new Attempt($s,3,2,$f->pg->hasher))->key->value);
    }
    public function testLostReceiptRecovery(): void
    {
        $f=$this->f;$s=$f->segment();$f->delivery->register($s);$f->lostAck=true;$f->delivery->send($s);
        self::assertSame('unknown',$f->ledger->state($s)['state']);$this->denies(fn()=>$f->delivery->send($s));
        $fresh=new IsolatedDatabase();$ledger=new Ledger(new Connection($fresh->pdo,$this->schema),$f->pg->hasher);
        $delivery=new Coordinator($ledger,$f,$f->pg->hasher,$f);$delivery->reconcile($s);$delivery->send($s);
        self::assertCount(1,$f->plays);self::assertCount(1,$f->syntheses);self::assertSame('delivered',$ledger->state($s)['state']);
    }
    public function testUnknownReconnectAndStaleReceipt(): void
    {
        $f=$this->f;$s=$f->segment();$f->delivery->register($s);$f->unknown=true;$f->delivery->send($s);$f->delivery->reconcile($s);
        self::assertSame('unknown',$f->ledger->state($s)['state']);$f->epoch=2;$f->delivery->reconnect($f->response);
        $this->denies(fn()=>$f->delivery->send($s));$a=new Attempt($s,0,2,$f->pg->hasher);
        $f->incoming=new PlayoutReceipt($f->ref('stale'),$f->response->response,1,$s->key,$a->key,1,7,'completed','client_ack');
        $this->denies(fn()=>$f->delivery->receive($s,'host:receipt'),IntegrityConflict::class);
        $f->notFound=true;$f->delivery->reconcile($s);$f->unknown=false;$f->delivery->send($s);
        self::assertSame($f->plays[0][0],$f->plays[1][0]);self::assertSame(7,$f->ledger->state($s)['confirmed']);
    }
    public function testBargeInAndCommands(): void
    {
        $f=$this->f;$s=$f->segment();$f->delivery->register($s);$f->nextOffset=3;$f->delivery->send($s);
        $command=$this->command();$f->delivery->queue($s,$command);
        $f->stopAllowed=false;$this->denies(fn()=>$f->delivery->interrupt($f->response));$f->stopAllowed=true;
        $f->epoch=2;$f->delivery->interrupt($f->response);
        self::assertSame('interrupted',$f->ledger->state($s)['state']);self::assertSame(3,$f->delivery->history($f->response)[0]['through_bytes']);
        $this->denies(fn()=>$f->delivery->send($s));$this->denies(fn()=>$f->delivery->display($s));$this->denies(fn()=>$f->delivery->command($s,$command));
        self::assertSame('revoked',$f->pg->environment->db->execute('SELECT state FROM '.$f->pg->environment->db->table('voice_commands'))->fetchColumn());
        self::assertSame(1,$f->stops);self::assertSame(0,$f->commands);self::assertCount(1,$f->plays);
    }
    public function testDisclosureAndRevoke(): void
    {
        $f=$this->f;$r=$f->response;
        $f->outputPolicy=new DisclosurePolicy($f->ref('speech-policy'),'v1',DisclosureMode::Buffered,true,65536);
        $f->response=new Response($r->context,$r->assistant,$r->turn,$r->run,$r->response,1,$r->destination,1,$f->outputPolicy,$f->pg->hasher);
        $s=$f->segment();$f->delivery->register($s);$f->checked=false;$this->denies(fn()=>$f->delivery->send($s));
        self::assertCount(0,$f->syntheses);$this->denies(fn()=>$f->delivery->register($f->segment('second',2)));
        $f->checked=true;$f->afterSynthesis=function() use($f):void { $f->voiceAllowed=false; };
        $this->denies(fn()=>$f->delivery->send($s));self::assertCount(0,$f->plays);
        $f->voiceAllowed=true;$f->afterSynthesis=null;$f->beforePlay=function() use($f):void { $f->voiceAllowed=false; };
        $this->denies(fn()=>$f->delivery->send($s));self::assertCount(0,$f->plays);
        $f->historyAllowed=false;$this->denies(fn()=>$f->delivery->history($f->response));
        $f->pg->host->policy='policy-revoked';$this->denies(fn()=>$f->delivery->display($s));
        // Stopping disclosure still works after policy pins cease to grant current permission.
        $f->pg->host->denied=true;$f->delivery->revoke($f->response);self::assertSame(1,$f->stops);self::assertSame(1,$f->retentions);
        $this->denies(fn()=>$f->ledger->history($f->response));
    }
    public function testTtsOutageTextContinuity(): void
    {
        $f=$this->f;$s=$f->segment();$f->delivery->register($s);$f->ttsOutage=true;
        $this->denies(fn()=>$f->delivery->send($s));self::assertSame('failed',$f->ledger->state($s)['state']);
        $f->displayUnknown=true;$f->delivery->display($s);self::assertSame('unknown',$f->ledger->state($s)['display_state']);
        $f->displayUnknown=false;$f->delivery->display($s);self::assertSame('displayed',$f->ledger->state($s)['display_state']);
        self::assertSame([], $f->delivery->history($f->response));$f->ttsOutage=false;$f->delivery->send($s);
        self::assertSame($f->syntheses[0][0],$f->syntheses[1][0]);self::assertSame(1,$f->pg->environment->db->execute('SELECT count(*) FROM '.$f->pg->environment->db->table('runs'))->fetchColumn());
    }
    public function testIdentityConflictsAndSchema(): void
    {
        $f=$this->f;$s=$f->segment();$f->delivery->register($s);$f->delivery->register($s);
        $this->denies(fn()=>$f->delivery->register($f->segment('segment:1',1,'one six')),IntegrityConflict::class);
        $this->denies(fn()=>$f->delivery->register($f->segment('another',1)),IntegrityConflict::class);
        $b=new Fixture($this->db->pdo,$this->schema,'tenant-b');$bs=$b->segment();$b->delivery->register($bs);
        self::assertNotSame($s->key->value,$bs->key->value);$b->delivery->send($bs);self::assertSame(0,$f->ledger->state($s)['confirmed']);
        Schema::install($f->pg->environment->db);$f->pg->environment->db->execute('UPDATE '.$f->pg->environment->db->table('voice_schema').' SET hash=\'corrupt\'');
        $this->denies(fn()=>Schema::install($f->pg->environment->db),IntegrityConflict::class);
    }
    public function testUiCommandGuards(): void
    {
        $f=$this->f;$s=$f->segment();$f->delivery->register($s);
        $this->denies(fn()=>$this->command('bad','money'),\InvalidArgumentException::class);
        $this->denies(fn()=>$f->delivery->queue($s,$this->command('expired','scroll','2026-11-03T09:00:00Z')));
        $this->denies(fn()=>$f->delivery->queue($s,$this->command('stale','scroll','2026-11-03T11:00:00Z',2)));
        $command=$this->command();$f->delivery->queue($s,$command);$this->denies(fn()=>$f->delivery->command($s,$command));
        $this->denies(fn()=>$f->delivery->queue($s,$this->command('command:1','scroll')),IntegrityConflict::class);
        $f->delivery->send($s);$f->delivery->command($s,$command);$f->delivery->command($s,$command);self::assertSame(1,$f->commands);
    }
    public function testReceiptBindingNegatives(): void
    {
        $f=$this->f;$s=$f->segment();$f->delivery->register($s);
        $gap=$f->segment('gap',3);$f->delivery->register($gap);$this->denies(fn()=>$f->delivery->send($gap));
        $unicode=$f->segment('unicode',2,'x я');$f->delivery->register($unicode);$this->denies(fn()=>$f->delivery->send($unicode));
        $f->nextOffset=3;$f->delivery->send($s);
        $a=new Attempt($s,0,1,$f->pg->hasher);$saved=$f->receipts[$a->key->value];$f->incoming=$saved;
        $f->delivery->receive($s,'host:receipt');self::assertSame(3,$f->ledger->state($s)['confirmed']);
        foreach ([['id'=>$saved->ref,'offset'=>7,'version'=>1],['id'=>$f->ref('wrong:version'),'offset'=>7,'version'=>2],
                  ['id'=>$f->ref('unaligned'),'offset'=>2,'version'=>1],['id'=>$f->ref('regression'),'offset'=>0,'version'=>1]] as $bad) {
            $f->incoming=new PlayoutReceipt($bad['id'],$f->response->response,$bad['version'],$s->key,$a->key,1,$bad['offset'],'failed','client_ack');
            $this->denies(fn()=>$f->delivery->receive($s,'host:receipt'),IntegrityConflict::class);
        }
        self::assertSame(3,$f->ledger->state($s)['confirmed']);
    }

    public function testCrashBeforePlaybackRecovery(): void
    {
        $f=$this->f;$s=$f->segment();$f->delivery->register($s);
        $process=proc_open([PHP_BINARY,__DIR__.'/crash.php',$this->schema],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        $deadline=hrtime(true)+5_000_000_000;
        do { $status=proc_get_status($process);if (!$status['running']) { break; }usleep(1000); } while(hrtime(true)<$deadline);
        if ($status['running']) { proc_terminate($process,9); }proc_close($process);
        self::assertSame("intent-saved\n",$stdout,$stderr);self::assertTrue($status['signaled']);self::assertSame(9,$status['termsig']);
        $this->denies(fn()=>$f->delivery->send($s));self::assertCount(0,$f->plays);
        $f->notFound=true;$f->delivery->reconcile($s);$f->delivery->send($s);
        self::assertCount(1,$f->plays);self::assertSame('delivered',$f->ledger->state($s)['state']);
    }
}
