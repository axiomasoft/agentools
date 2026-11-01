<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Operations;

require_once __DIR__.'/Fixture.php';

use PHPUnit\Framework\TestCase;

final class RecoveryTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f=new Fixture(); }
    protected function tearDown(): void { if (isset($this->f)) { $this->f->close(); } }
    public function testCrashAfterCancellationCommit(): void
    {
        $worker=new Worker($this->f->f->schema,'crash'); $worker->go(); self::assertSame('committed',$worker->line());
        $worker->kill(); self::assertNotSame(0,$worker->finish()['exit']);
        $fresh=new Fixture($this->f->f->schema);
        $result=$fresh->operations->cancelRun($fresh->op('run_cancel',$fresh->run,0,0));
        self::assertSame('cancelling',$result['status']); self::assertSame(1,$result['version']);
        self::assertSame(1,$this->f->f->count('operator_operations')); self::assertSame(1,$this->f->f->count('operator_operation_receipts'));
        self::assertSame(0,$this->f->f->count('example_owner_writes'));
    }
    public function testCompetingCancellation(): void
    {
        $pdo=$this->f->f->database->pdo; $pdo->beginTransaction();
        $pdo->exec('LOCK TABLE '.$this->f->f->composition->environment->db->table('runs').' IN ACCESS EXCLUSIVE MODE');
        $workers=[];
        try {
            foreach (['a','b'] as $name) { $workers[]=new Worker($this->f->f->schema,$name); }
            foreach ($workers as $worker) { $worker->go(); }
            $deadline=hrtime(true)+10_000_000_000; $waiting=0;
            do {
                $stmt=$pdo->prepare("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND application_name LIKE :prefix AND wait_event_type='Lock'");
                $stmt->execute(['prefix'=>'operator_'.$this->f->f->schema.'%']); $waiting=(int)$stmt->fetchColumn();
                if ($waiting===2) { break; } usleep(1000);
            } while (hrtime(true)<$deadline);
            self::assertSame(2,$waiting,'Both workers must overlap at a real database barrier');
        } finally { $pdo->rollBack(); }
        foreach ($workers as $worker) { $result=$worker->finish(); self::assertSame(0,$result['exit'],$result['stderr']); }
        self::assertSame(1,$this->f->f->count('operator_operation_receipts'));
        self::assertSame(1,(int)$this->f->f->composition->environment->db->execute('SELECT version FROM '.$this->f->f->composition->environment->db->table('runs'))->fetchColumn());
    }
}

final class Worker
{
    /** @var resource */ private $process;
    /** @var array<int,resource> */ private array $pipes;
    private bool $closed=false;
    public function __construct(string $schema,string $mode)
    {
        $p=proc_open([PHP_BINARY,__DIR__.'/worker.php',$schema,$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if (!is_resource($p)) { throw new \RuntimeException('Worker unavailable'); }
        $this->process=$p; $this->pipes=$pipes; stream_set_timeout($pipes[1],10);
        if ($this->line()!=='ready') { throw new \RuntimeException('Worker not ready'); }
    }
    public function line(): string { return trim((string)fgets($this->pipes[1])); }
    public function go(): void { fwrite($this->pipes[0],"go\n"); fflush($this->pipes[0]); }
    public function kill(): void { proc_terminate($this->process,9); }
    /** @return array{exit:int,stdout:string,stderr:string} */
    public function finish(): array {
        fclose($this->pipes[0]); $out=stream_get_contents($this->pipes[1]); $err=stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]); fclose($this->pipes[2]); $exit=proc_close($this->process); $this->closed=true;
        return ['exit'=>$exit,'stdout'=>$out===false?'':$out,'stderr'=>$err===false?'':$err];
    }
    public function __destruct() { if (!$this->closed) { $this->kill(); foreach ($this->pipes as $p) { if (is_resource($p)) { fclose($p); } } proc_close($this->process); } }
}
