<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler\Tests;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, UtcInstant};
use AgenToolsPlatform\Core\Security\TrustedInput;
use AgenToolsPlatform\Scheduler\{Scanner, Store};
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f = new Fixture(); }
    protected function tearDown(): void { $this->f->close(); }
    private function register(): \AgenToolsPlatform\Scheduler\Schedule
    {
        $s = $this->f->schedule(); $this->f->store->register($s,$this->f->authority->context()); return $s;
    }
    private function oneEach(int $count): void
    {
        $j = $this->f->runtime->current();
        foreach (['scheduler_occurrences','turns','runs','inbox','outbox'] as $table) { self::assertSame($count,$j->count($table),$table); }
    }
    public function testConcurrentScanners(): void
    {
        $s = $this->register(); $directory = sys_get_temp_dir().'/agentools_sched_'.bin2hex(random_bytes(8)); mkdir($directory);
        $processes = []; $pipes = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $io = []; $p = proc_open([PHP_BINARY,__DIR__.'/worker.php',$this->f->runtime->schema,$directory,(string)$i,'scan'],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$io);
                self::assertIsResource($p); $processes[] = $p; $pipes[] = $io;
            }
            $deadline = microtime(true) + 10;
            while ((!is_file($directory.'/ready-0') || !is_file($directory.'/ready-1')) && microtime(true) < $deadline) { usleep(10000); }
            self::assertFileExists($directory.'/ready-0'); self::assertFileExists($directory.'/ready-1'); touch($directory.'/go');
            $results = []; $exits = [];
            foreach ($processes as $i => $process) {
                fclose($pipes[$i][0]); $out = stream_get_contents($pipes[$i][1]); $err = stream_get_contents($pipes[$i][2]);
                fclose($pipes[$i][1]); fclose($pipes[$i][2]); $exits[] = proc_close($process);
                self::assertSame(0,$exits[$i],$err === false ? '' : $err);
                self::assertIsString($out); $decoded = json_decode($out,true,32,JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded); $results[] = $decoded;
            }
            $processes = [];
            self::assertSame($results[0],$results[1]); self::assertCount(1,$results[0]); $this->oneEach(1);
            self::assertSame(0,$this->f->runtime->current()->count('write_provider'));
            Evidence::record('CM-045',['workers'=>2,'exits'=>$exits,'same_receipts'=>$results[0]===$results[1],'counts'=>1,'receipts'=>$results[0]]);
        } finally {
            foreach ($processes as $p) { if (is_resource($p)) { proc_terminate($p,9); proc_close($p); } }
            foreach (glob($directory.'/*') ?: [] as $path) { unlink($path); } rmdir($directory);
        }
    }
    public function testDstPersisted(): void
    {
        $s = $this->f->schedule(zone:'America/New_York',time:'01:30:00');
        $this->f->store->register($s,$this->f->authority->context());
        $receipt = (new Scanner($this->f->store))->scan($s->id); self::assertCount(1,$receipt);
        $handle = $this->f->store->handle($s,new UtcInstant('2026-11-01T05:30:00Z'));
        $saved = $this->f->store->resolve($handle,$this->f->authority->context());
        self::assertSame('2026-11-01T05:30:00Z',$saved['planned']->value);
        self::assertSame('America/New_York',$saved['schedule']->timezone); self::assertSame(1,$saved['schedule']->version);
        $this->oneEach(1);
    }
    public function testCatchupBoundAndDuplicateRetry(): void
    {
        $s = $this->f->schedule(start:'2026-09-12T00:00:00Z'); $this->f->store->register($s,$this->f->authority->context());
        $scanner = new Scanner($this->f->store); $first = $scanner->scan($s->id); self::assertCount(3,$first);
        self::assertEquals($first,$scanner->scan($s->id)); $this->oneEach(3);
        $this->f->authority->time = new UtcInstant('2026-11-01T12:00:01Z');
        self::assertSame([],$scanner->scan($s->id)); $this->oneEach(3);
    }
    public function testStableIdentityAndHashConflict(): void
    {
        $s = $this->register(); $scanner = new Scanner($this->f->store); $first = $scanner->scan($s->id);
        self::assertEquals($first,$scanner->scan($s->id,new UtcInstant('2026-11-01T12:00:00.000000Z')));
        $different = $this->f->schedule(zone:'Europe/Volgograd');
        try { $this->f->store->register($different,$this->f->authority->context()); self::fail('Same version differing hash'); }
        catch (IntegrityConflict $error) { self::assertStringContainsString('conflict',strtolower($error->getMessage())); }
        $v2 = $this->f->schedule(version:2,zone:'Europe/Volgograd'); $this->f->store->register($v2,$this->f->authority->context());
        $at = new UtcInstant('2026-11-01T12:00:00Z'); $prepared = $this->f->authority->prepare($this->f->authority->context(),$v2,$at,$this->f->store->inputRef($v2,$at));
        try {
            $this->f->store->accept($s->id,2,$at,new TrustedInput($this->f->authority->context(),$this->f->store->source($v2,$at),$prepared->payload),$prepared->snapshot);
            self::fail('Historical occurrence cannot be retargeted');
        } catch (IntegrityConflict $error) { self::assertStringContainsString('conflict',strtolower($error->getMessage())); }
        $saved = $this->f->store->resolve($this->f->store->handle($s,$at),$this->f->authority->context());
        self::assertSame(1,$saved['schedule']->version); $this->oneEach(1);
        $j = $this->f->runtime->current();
        try {
            $j->db->execute('UPDATE '.$j->db->table('scheduler_versions').' SET config=\'{}\' WHERE tenant_id=:tenant', ['tenant'=>$j->tenant->value]);
            self::fail('Immutable version UPDATE');
        } catch (ContractViolation $error) { self::assertSame(ErrorCode::InvalidInput,$error->errorCode); }
    }
    public function testAtomicRollbackAndPendingBatchRecovery(): void
    {
        $s = $this->register(); $j = $this->f->runtime->current();
        $fault = new Store($j->environment,$this->f->authority,static function (): never { throw new \RuntimeException('injected before intake commit'); });
        try { (new Scanner($fault))->scan($s->id); self::fail('Injected rollback'); }
        catch (\RuntimeException $error) { self::assertSame('injected before intake commit',$error->getMessage()); }
        $this->oneEach(0);
        $this->f->authority->time = new UtcInstant('2026-11-02T12:00:00Z');
        $recovered = (new Scanner($this->f->store))->scan($s->id); self::assertCount(1,$recovered); $this->oneEach(1);
        $saved = $this->f->store->resolve($this->f->store->handle($s,new UtcInstant('2026-11-01T12:00:00Z')),$j->context);
        self::assertSame('2026-11-01T12:00:00Z',$saved['planned']->value);
    }
    public function testStoppedAndInventedTimeDenied(): void
    {
        $s = $this->register(); $context = $this->f->authority->context();
        try { $this->f->store->plan($s->id,$context,new UtcInstant('2026-11-02T12:00:00Z')); self::fail('Future scan time'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::InvalidInput,$error->errorCode); }
        $this->f->store->plan($s->id,$context,$this->f->authority->now());
        $invented = new UtcInstant('2026-11-01T11:59:00Z'); $p = $this->f->authority->prepare($context,$s,$invented,$this->f->store->inputRef($s,$invented));
        try { $this->f->store->accept($s->id,1,$invented,new TrustedInput($context,$this->f->store->source($s,$invented),$p->payload),$p->snapshot); self::fail('Unplanned timestamp'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::InvalidInput,$error->errorCode); }
        $this->f->store->stop($s->id,$context);
        $at = new UtcInstant('2026-11-01T12:00:00Z'); $p = $this->f->authority->prepare($context,$s,$at,$this->f->store->inputRef($s,$at));
        try { $this->f->store->accept($s->id,1,$at,new TrustedInput($context,$this->f->store->source($s,$at),$p->payload),$p->snapshot); self::fail('Stopped schedule'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::Cancelled,$error->errorCode); }
        $this->oneEach(0);
    }
    public function testCrashAfterCommit(): void
    {
        $s = $this->register(); $directory = sys_get_temp_dir().'/agentools_sched_crash_'.bin2hex(random_bytes(8)); mkdir($directory);
        $pipes = []; $process = proc_open([PHP_BINARY,__DIR__.'/worker.php',$this->f->runtime->schema,$directory,'0','commit'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); self::assertIsResource($process);
        try {
            touch($directory.'/go'); $deadline = microtime(true) + 10;
            while (!is_file($directory.'/committed') && microtime(true) < $deadline) { usleep(10000); }
            self::assertFileExists($directory.'/committed'); $this->oneEach(1);
            self::assertTrue(proc_terminate($process,9));
            $state = proc_get_status($process);
            while ($state['running'] && microtime(true) < $deadline) { usleep(10000); $state = proc_get_status($process); }
            self::assertTrue($state['signaled']); self::assertSame(9,$state['termsig']);
            foreach ($pipes as $pipe) { fclose($pipe); } $pipes = []; $exit = proc_close($process); $process = null;
            $firstBytes = file_get_contents($directory.'/committed'); self::assertIsString($firstBytes);
            $first = json_decode($firstBytes,true,32,JSON_THROW_ON_ERROR);
            $retry = (new Scanner($this->f->store))->scan($s->id);
            self::assertSame($first,array_map(static fn ($r): array => $r->toArray(),$retry)); $this->oneEach(1);
            Evidence::record('scheduler.crash-after-commit',['signal'=>9,'signaled'=>true,'worker_exit'=>$exit,'same_refs'=>true,'counts'=>1]);
        } finally {
            if (is_resource($process)) { proc_terminate($process,9); foreach ($pipes as $pipe) { fclose($pipe); } proc_close($process); }
            foreach (glob($directory.'/*') ?: [] as $path) { unlink($path); } rmdir($directory);
        }
    }
}
