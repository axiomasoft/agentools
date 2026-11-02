<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Golden;

use AgenToolsPlatform\Core\Common\ContractViolation;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Process.php';

final class RecoveryTest extends TestCase
{
    use Fixture;
    public function testCorrectionAfterIntentIsKnownNotSent(): void
    {
        $j=$this->journey();$old=$j->ingest();$j->prepare();
        $j->host->barrier=function(string $boundary) use ($j,$old): void { if ($boundary==='intent') { $j->ingest('v2',$old); } };
        try { $j->run();self::fail('Corrected source reached provider'); } catch (ContractViolation) { self::assertTrue(true); }
        self::assertSame(0,$this->countRows('knowledge_demo_calls'));self::assertSame(0,$this->countRows('usage_versions'));self::assertSame(0,$this->countRows('responses'));
        $row=$this->database->pdo->query('SELECT outcome FROM invocation_results')->fetchColumn();self::assertSame('failed',$row);
        $state=json_decode($this->database->pdo->query('SELECT state_ref FROM run_steps')->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
        self::assertSame(0,$state['request_count']);self::assertFalse($state['possible_cost']);
        $this->observation('pre-send-correction',['provider_calls'=>0,'usage_rows'=>0,'outcome'=>$row,'request_count'=>$state['request_count'],'possible_cost'=>$state['possible_cost']]);
    }
    public function testProviderCrashLookupAndRestartDoNotDuplicate(): void
    {
        $worker=new Process('crash',$this->schema,'support');self::assertSame('READY',$worker->line());$worker->go();
        self::assertSame('PROVIDER_SAVED',$worker->line());$status=$worker->kill();self::assertTrue($status['signaled']);self::assertSame(9,$status['termsig']);
        self::assertSame(1,$this->countRows('knowledge_demo_calls'));self::assertSame(0,$this->countRows('responses'));
        $j=$this->journey(key:'crash');$j->ingest();$j->prepare();$j->host->expireWorker();self::assertSame('complete',$j->run());
        self::assertSame('delivered',$j->deliver());
        foreach (['knowledge_demo_calls','responses','usage_versions','invocation_intents','demo_messages'] as $table) { self::assertSame(1,$this->countRows($table),$table); }
        $this->observation('recovery',['signal'=>9,'signaled'=>true,'worker_exit'=>$status['exitcode'],'provider_calls'=>1,'responses'=>1,'usage_versions'=>1,'delivery_messages'=>1,'lookup'=>'verified-same-request']);
    }
}
