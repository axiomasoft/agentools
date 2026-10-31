<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Common\{CanonicalHasher, Ref, RunCheckpoint, RunId, RunStatus, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Runtime\{RunCommand, RunState};
use AgenToolsPlatform\Testing\{Failpoints, FaultPoint, InjectedFault, ManualClock, RepeatableScheduler};
use AgenToolsPlatform\Tests\{Harness as H, JcsAdapter};

require_once __DIR__ . '/bootstrap.php';
$fixture = json_decode((string) file_get_contents(dirname(__DIR__,3) . '/fixtures/platform/v1/testkit/schedules.json'),true,512,JSON_THROW_ON_ERROR);
if (!is_array($fixture) || !is_array($fixture['interleaving'] ?? null)) { throw new RuntimeException('Missing schedule fixture'); }
$order = [];
foreach ($fixture['interleaving'] as $name) {
    if (!is_string($name)) { throw new RuntimeException('Invalid scheduled task'); }
    $order[] = $name;
}
$cases = [
 'testkit.commit-crash-replay' => static function (): void {
    foreach ([FaultPoint::BeforeCommit,FaultPoint::AfterCommit] as $point) {
        $tenant = new TenantId('tenant-a');$run = new RunId($tenant,'run-1');
        $stored = new RunState(new RunCheckpoint($run,RunStatus::Accepted,1,1));
        $command = new RunCommand($run,new Ref($tenant,'cmd'),new Ref($tenant,'step'),RunStatus::Preparing,1,1,null,new CanonicalHasher(new JcsAdapter()));
        $failpoints = new Failpoints();$failpoints->arm($point);
        $scheduler = new RepeatableScheduler();
        $scheduler->add('worker',[static function () use (&$stored,$command,$failpoints): void {
            $candidate = $stored->apply($command);$failpoints->hit(FaultPoint::BeforeCommit);
            $stored = $candidate;$failpoints->hit(FaultPoint::AfterCommit);
        }]);
        H::rejects(static fn () => $scheduler->run(['worker']),InjectedFault::class);
        H::check($scheduler->trace() === ['worker']);
        $failpoints->assertConsumed();
        H::check($stored->checkpoint->version === ($point === FaultPoint::BeforeCommit ? 1 : 2));
        $stored = $stored->apply($command);H::check($stored->checkpoint->version === 2 && count($stored->commands) === 1);
        H::check($stored->outcome($command)->checkpoint->version === 2);
    }
 },
 'testkit.scheduler-repeatable-fail-closed' => static function () use ($order): void {
    H::rejects(static fn () => (new RepeatableScheduler())->run([]),InvalidArgumentException::class);
    $run = static function () use ($order): array {
        $trace = [];$scheduler = new RepeatableScheduler();
        $scheduler->add('a',[static function () use (&$trace): void {$trace[]='a1';},static function () use (&$trace): void {$trace[]='a2';}]);
        $scheduler->add('b',[static function () use (&$trace): void {$trace[]='b1';}]);
        H::rejects(static fn () => $scheduler->run(['a','b']),InvalidArgumentException::class);
        H::rejects(static fn () => $scheduler->run(['a','b','a','unknown']),InvalidArgumentException::class);
        $scheduler->run($order);H::check($scheduler->trace() === ['a','b','a']);
        return $trace;
    };
    H::check($run() === ['a1','b1','a2'] && $run() === $run());
    foreach (FaultPoint::cases() as $point) {
        $faults = new Failpoints();$faults->arm($point,2);
        H::rejects(static fn () => $faults->assertConsumed(),LogicException::class);
        $faults->hit($point);H::rejects(static fn () => $faults->hit($point),InjectedFault::class);$faults->assertConsumed();$faults->hit($point);
    }
 },
 'testkit.real-dedup-unknown-effect' => static function (): void {
    $clock = new ManualClock(new UtcInstant('2026-10-31T12:00:00Z'));$faults = new Failpoints();$faults->arm(FaultPoint::AfterExternal);
    $tool = new class($faults) implements \AgenTools\Contracts\ToolPort {
        public int $effects = 0;
        public function __construct(private Failpoints $faults) {}
        public function call(string $tool,array $arguments,string $idempotencyKey): \AgenTools\Error\ToolExecutionResult {
            ++$this->effects;$this->faults->hit(FaultPoint::AfterExternal);return \AgenTools\Error\ToolExecutionResult::success(['ok'=>true]);
        }
    };
    $store = new \AgenTools\TestKit\InMemoryDeduplicationStore(new \AgenTools\Idempotency\DeduplicationPolicy(new DateInterval('P1D'),new DateInterval('PT0S'),new DateInterval('PT0S')));
    $coordinator = new \AgenTools\Idempotency\IdempotencyCoordinator($store,
        new \AgenTools\Idempotency\ToolIdempotencyCatalog(['write'=>\AgenTools\Idempotency\ToolIdempotencyCapability::AtMostOnceOnly],['write'=>'write']),
        $tool,new \AgenTools\Schema\Canonicalizer(),'tenant-a','action-1',clock:static fn (): DateTimeImmutable => new DateTimeImmutable($clock->now()->value));
    H::rejects(static fn () => $coordinator->call('write',['amount'=>1],'at1:abcdefgh'),InjectedFault::class);
    $clock->advanceSeconds(31);$unknown = $coordinator->call('write',['amount'=>1],'at1:abcdefgh');
    H::check($unknown->error?->code === 'agentools.precondition.idempotency_ambiguous_effect' && $tool->effects === 1);
    $conflict = $coordinator->call('write',['amount'=>2],'at1:abcdefgh');
    H::check($conflict->isError() && $tool->effects === 1);$faults->assertConsumed();
 },
];
if (realpath($argv[0] ?? '') === __FILE__) { H::run($cases,'contract-scripted','contract-scripted'); }
return $cases;
