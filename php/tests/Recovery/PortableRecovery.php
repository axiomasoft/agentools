<?php

declare(strict_types=1);

namespace AgenTools\Tests\Recovery;

require_once dirname(__DIR__,2).'/vendor/autoload.php';
require_once dirname(__DIR__).'/Confirmation/PlatformBindingFixture.php';

use AgenTools\Action\{ActionEvent, ActionReducer, Actor, FoldedAction, JournalHashChain};
use AgenTools\Contracts\{ExternalEffectProbe, ToolPort};
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Idempotency\{ArgumentsFingerprint, DeduplicationPolicy, IdempotencyCoordinator, IdempotencyKey, IdempotencyRequest, IdempotencyScope, StoredToolResult, ToolIdempotencyCapability, ToolIdempotencyCatalog};
use AgenTools\Schema\Canonicalizer;
use AgenTools\TestKit\InMemoryDeduplicationStore;
use AgenTools\Tests\Confirmation\PlatformBindingFixture;

final class PortableRecovery
{
    /** @return list<array<string,mixed>> */
    public static function cases(): array
    {
        $raw=file_get_contents(dirname(__DIR__,3).'/fixtures/platform/v1/recovery/portable.json');
        if ($raw===false) { throw new \RuntimeException('Required portable fixture missing'); }
        /** @var array{cases:list<array<string,mixed>>} $data */
        $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (count($data['cases'])!==39) { throw new \RuntimeException('Exact portable case set changed'); }
        return $data['cases'];
    }

    /**
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    public static function run(array $case): array
    {
        return match($case['kind']) {
            'action'=>self::action($case), 'confirmation'=>self::confirmation($case), 'retry'=>self::retry($case),
            default=>throw new \RuntimeException('Unsupported required portable case'),
        };
    }

    /**
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    private static function action(array $case): array
    {
        $state=new FoldedAction(); $reducer=new ActionReducer(hashChain:new JournalHashChain(new Canonicalizer())); $accepted=true;
        /** @var list<array{kind:string,payload?:array<string,mixed>,epoch?:int,seq?:int,expected_prev_seq?:int}> $events */
        $events=$case['events'];
        foreach ($events as $event) {
            try {
                $state=$reducer->apply($state,new ActionEvent('event-'.($state->seq+1),'action',$event['seq']??$state->seq+1,$event['expected_prev_seq']??$state->seq,
                    '2026-11-01T00:00:00Z',new Actor('host','owner'),$event['kind'],1,'2026-09',$event['payload']??[],$event['epoch']??null,$state->previousHash));
            } catch (\AgenTools\Action\LifecycleException) { $accepted=false; break; }
        }
        $effects=[]; foreach ($state->effects as $id=>$effect) { $effects[]=['id'=>$id,'status'=>$effect->status->value,'epoch'=>$effect->epoch]; }
        return ['accepted'=>$accepted,'status'=>$state->status?->value,'seq'=>$state->seq,'cancelled'=>$state->cancellationRequested,
            'outcome'=>match($state->status?->value){'completed'=>'succeeded','failed'=>'failed','applying','reconciliation_required'=>'unknown',default=>null},'effects'=>$effects];
    }

    /**
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    private static function confirmation(array $case): array
    {
        /** @var array<string,mixed> $change */ $change=$case['change'];
        /** @var array<string,mixed> $args */ $args=$case['arguments'];
        if (($change['consume_after_denial']??false)!==true) { return ['accepted'=>!PlatformBindingFixture::rejectsCase($args,$change)]; }
        $f=new PlatformBindingFixture(); $token=$f->service->prepare('action-1','platform_confirmed_action',$args,['summary'=>'Exact write','changes'=>[$args]]);
        $f->proof='none'; try { $f->service->commit($token->value,$args); } catch (\AgenTools\Confirmation\ConfirmationFailure) {}
        $f->proof='ui';
        try { $f->service->commit($token->value,$args); return ['accepted'=>true]; }
        catch (\AgenTools\Confirmation\ConfirmationFailure) { return ['accepted'=>false]; }
    }

    /**
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    private static function retry(array $case): array
    {
        $store=new InMemoryDeduplicationStore(new DeduplicationPolicy(new \DateInterval('P1D'),new \DateInterval('PT0S'),new \DateInterval('PT0S')));
        $start=new \DateTimeImmutable('2026-11-01T00:00:00Z'); $canonicalizer=new Canonicalizer();
        $store->acquire(new IdempotencyRequest(new IdempotencyScope('tenant-a','write',new IdempotencyKey('at1:abcdefgh')),
            ArgumentsFingerprint::fromArguments(['amount'=>1],$canonicalizer),'action'),$start,new \DateInterval('PT30S'));
        $port=new class implements ToolPort {
            public int $calls=0;
            public function call(string $tool,array $arguments,string $idempotencyKey): ToolExecutionResult { ++$this->calls; return ToolExecutionResult::success(['receipt'=>'first']); }
        };
        $cap=match($case['capability']) {'native_key'=>ToolIdempotencyCapability::NativeKey,'read_before_write'=>ToolIdempotencyCapability::ReadBeforeWrite,
            'at_most_once_only'=>ToolIdempotencyCapability::AtMostOnceOnly,default=>ToolIdempotencyCapability::Unknown};
        $probe=$case['probe']==='missing'?null:new class($case['probe']==='found') implements ExternalEffectProbe {
            public function __construct(private bool $found) {}
            public function find(string $tool,string $idempotencyKey): ?StoredToolResult { return $this->found?new StoredToolResult(value:['receipt'=>'first']):null; }
        };
        if (!is_int($case['elapsed_seconds'])) { throw new \RuntimeException('Invalid fixture clock'); }
        $now=$start->modify('+'.$case['elapsed_seconds'].' seconds');
        $coordinator=new IdempotencyCoordinator($store,new ToolIdempotencyCatalog(['write'=>$cap]),$port,$canonicalizer,'tenant-a','action',probe:$probe,clock:static fn ()=>$now);
        $args=['amount'=>$case['changed_hash']?2:1]; $first=$coordinator->call('write',$args,'at1:abcdefgh'); $second=$coordinator->call('write',$args,'at1:abcdefgh');
        return ['accepted'=>!$first->isError(),'calls'=>$port->calls,'code'=>$first->error?->code,
            'replay_equal'=>$first->value===$second->value && $first->error?->code===$second->error?->code];
    }
}
