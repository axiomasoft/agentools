<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Tests\Journey;

use AgenToolsPlatform\Core\Actions\CancelRequest;
use AgenToolsPlatform\Core\Common\{ActionId, ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref, UtcInstant};
use AgenToolsPlatform\Examples\Voice\Journey;
use AgenToolsPlatform\Loqum\Delivery\{Coordinator, Ledger, UiCommand};
use AgenToolsPlatform\Loqum\Ingress\Rejection;
use AgenToolsPlatform\Postgres\Runtime\Connection as C;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class VoiceJourneyTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    private Journey $j;
    private array $events;
    private array $extra=[];
    protected function setUp(): void
    {
        $this->database=new IsolatedDatabase();$this->schema='p3_'.bin2hex(random_bytes(12));Journey::install($this->database,$this->schema);
        $this->j=new Journey($this->database,$this->schema,'tenant-a');
        $this->events=json_decode(file_get_contents(dirname(__DIR__,4).'/examples/platform/voice/events.json'),true,512,JSON_THROW_ON_ERROR);
    }
    protected function tearDown(): void
    {
        if (isset($this->j)) {
            $path=getenv('AGENTOOLS_VOICE_JOURNEY_OBSERVATIONS');
            if (is_string($path) && $path!=='') {
                $facts=['turns'=>$this->j->count('turns'),'runs'=>$this->j->count('runs'),'model_calls'=>$this->j->modelCalls(),
                    'native_invocations'=>$this->j->count('invocation_intents'),'usage_versions'=>$this->j->count('usage_versions'),
                    'actions'=>$this->j->count('action_submissions'),'displayed_segments'=>$this->j->count('voice_demo_display'),
                    'messages'=>$this->j->count('voice_demo_messages'),'ui_executions'=>$this->j->count('voice_demo_commands'),
                    'cancellation_requests'=>$this->j->count('action_cancellation_requests')]+$this->extra;
                file_put_contents($path,json_encode(['method'=>$this->name(),'facts'=>$facts],JSON_THROW_ON_ERROR).PHP_EOL,FILE_APPEND|LOCK_EX);
            }
        }
        if (isset($this->database,$this->schema)) { if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); }$this->database->dropSchema($this->schema); }
    }
    private function denies(callable $operation,string $type=ContractViolation::class): \Throwable
    {
        try { $operation(); } catch (\Throwable $error) { self::assertInstanceOf($type,$error);return $error; }self::fail('Expected boundary rejection');
    }
    private function finalOutput(): array { $this->j->accept($this->events['final']);return $this->j->output(); }
    private function command(string $id='highlight:1',bool $expired=false,int $anchor=0): UiCommand
    {
        $j=$this->j;$s=$j->segments[$anchor];$now=new \DateTimeImmutable($j->host->now()->value);
        return new UiCommand($j->ref($id),'highlight',1,'voice-ui/v1',$j->host->revision(),
            new UtcInstant($now->modify($expired?'-1 second':'+5 minutes')->format('Y-m-d\TH:i:s\Z')),
            $s->key,$j->ref('ui:payload'),new PayloadHash('sha-256:'.str_repeat('a',64)),$j->hasher);
    }
    private function nativeCounts(int $turns,int $models,int $actions=0): void
    {
        self::assertSame($turns,$this->j->count('turns'));self::assertSame($turns,$this->j->count('runs'));
        self::assertSame($models,$this->j->count('voice_demo_provider'));self::assertSame($models,$this->j->count('usage_versions'));
        self::assertSame($models,$this->j->modelCalls());
        self::assertSame($actions,$this->j->count('action_submissions'));
    }
    public function testInterim(): void
    {
        foreach ($this->events['noise_partial'] as $e) {
            $error=$this->denies(fn()=>$this->j->accept($e),Rejection::class);self::assertSame('INTERIM_TRANSCRIPT',$error->getMessage());
        }
        $this->nativeCounts(0,0);self::assertSame(0,$this->j->count('voice_demo_messages'));self::assertSame(0,$this->j->count('proposal_map'));
    }
    public function testReplay(): void
    {
        $j=$this->j;$j->propose=true;$first=$j->accept($this->events['final']);self::assertSame('waiting_action',$j->drive()->stage());
        $before=$j->db->row('SELECT id,request_hash FROM '.$j->db->table('action_submissions'));
        $j->change(['media_epoch'=>2],'reconnect');$again=$j->accept($this->events['reconnect_final']);$j->drive();
        self::assertSame($first->toArray(),$again->toArray());self::assertSame($before,$j->db->row('SELECT id,request_hash FROM '.$j->db->table('action_submissions')));
        $this->nativeCounts(1,1,1);self::assertSame(1,$j->count('proposal_map'));self::assertSame(0,$j->count('effect_intents'));
    }
    public function testStaleEpoch(): void
    {
        $this->j->change(['media_epoch'=>2],'reconnect');$e=$this->denies(fn()=>$this->j->accept($this->events['stale_epoch']),Rejection::class);
        self::assertSame('STALE_MEDIA_EPOCH',$e->getMessage());$this->nativeCounts(0,0);
    }
    public function testStaleContext(): void
    {
        $this->j->change(['context_revision'=>4],'context:4');$e=$this->denies(fn()=>$this->j->accept($this->events['stale_context']),Rejection::class);
        self::assertInstanceOf(Rejection::class,$e);self::assertSame('STALE_CONTEXT_REVISION',$e->reason);self::assertSame(4,$e->currentRevision);
        self::assertSame(0,$this->j->count('voice_demo_snapshots'));$this->nativeCounts(0,0);
    }
    public function testBargeIn(): void
    {
        $j=$this->j;$segments=$this->finalOutput();$j->delivery->send($segments[0]);foreach ($segments as $s) { $j->delivery->display($s); }
        $command=$this->command(anchor:1);self::assertSame('queued',$j->queueCommand($segments[1],$command));$j->delivery->interrupt($j->response);
        self::assertCount(3,$segments);self::assertSame(3,$j->count('voice_demo_display'));self::assertCount(1,$j->delivery->history($j->response));self::assertSame('Первый.',$j->heard());
        self::assertTrue($j->db->row('SELECT stopped FROM '.$j->db->table('voice_responses'))['stopped']);
        self::assertSame('revoked',$j->db->execute('SELECT state FROM '.$j->db->table('voice_commands'))->fetchColumn());
        $this->denies(fn()=>$j->delivery->command($segments[1],$command));self::assertSame(0,$j->count('voice_demo_commands'));
    }
    public function testEffectIndependence(): void
    {
        require_once dirname(__DIR__,4).'/packages/agentools-core/tests/Actions/Confirmation/Fixture.php';
        $f=new \AgenToolsPlatform\Tests\Confirmation\Fixture($this->schema);$f->owner->provision();$c=$f->composition;
        $submission=$c->submission(context:$c->context($this->j->session->value));$p=$c->confirmation->prepare($submission);$f->consent($p,'voice');
        $c->confirmation->approve($p->token,$submission);$grant=$c->confirmation->commit($p->token,$submission);$f->owner->write($grant,$submission);
        $segments=$this->finalOutput();$this->j->delivery->send($segments[0]);$this->j->delivery->interrupt($this->j->response);
        self::assertSame('completed',$c->actions->status($grant->action,$submission->request->context)->receipt->wireStatus);self::assertSame(1,$f->count('example_owner_writes'));
        // A separate explicit cancellation request is required for another pending Action.
        $j=$this->j;$j->propose=true;$j->accept(dict_event($this->events['final'],['utterance_id'=>'ut-2']));$j->output();
        $row=$j->db->row('SELECT id FROM '.$j->db->table('action_submissions').' WHERE definition_ref->>\'id\'=\'voice.example.read\'');
        self::assertNotNull($row);$id=new ActionId($j->tenant,C::text($row,'id'));$before=$j->actions->status($id,$j->input->context);
        $j->delivery->interrupt($j->response);self::assertSame($before->receipt->toArray(),$j->actions->status($id,$j->input->context)->receipt->toArray());
        self::assertSame(0,$j->count('action_cancellation_requests'));
        $j->actions->cancel($id,new CancelRequest($j->ref('explicit:cancel'),$before->receipt->version,$j->input->context));
        $state=$j->db->transaction(fn()=>(new \AgenToolsPlatform\Postgres\Confirmation\Journal($j->environment))->read($id,true));
        self::assertTrue($state->cancellationRequested);self::assertSame(1,$j->count('action_cancellation_requests'));
        self::assertNull($j->actions->status($id,$j->input->context)->outcome());self::assertSame(1,$f->count('example_owner_writes'));
    }
    public function testExpiredUiCommand(): void
    {
        $j=$this->j;$segments=$this->finalOutput();$j->delivery->send($segments[0]);$expired=$this->command(expired:true);
        for ($i=0;$i<2;++$i) { self::assertSame('expired',$j->queueCommand($segments[0],$expired)); }
        self::assertSame(0,$j->count('voice_demo_commands'));self::assertSame(0,$j->count('voice_commands'));
        // Host-facing projection of the existing stale_context refusal; no new wire enum.
        self::assertLessThanOrEqual(new \DateTimeImmutable($j->host->now()->value),new \DateTimeImmutable($expired->expiresAt->value));
        $state=json_decode($j->db->execute('SELECT state FROM '.$j->db->table('voice_demo_checkpoints').' WHERE id=\'command:highlight:1:expired\'')->fetchColumn(),true);
        self::assertSame('expired',$state['command_state']);
    }
    public function testConsentRevoke(): void
    {
        $j=$this->j;$segments=$this->finalOutput();foreach (['recording','memory','export'] as $kind) { $j->host->operation($kind,'permitted:'.$kind); }
        $j->change(['recording'=>false,'memory'=>false,'export'=>false,'context_revision'=>2,'policy_revision'=>'policy-2'],'consent:revoked');$j->delivery->revoke($j->response);
        foreach (['recording','memory','export'] as $kind) { $this->denies(fn()=>$j->host->operation($kind,'forbidden:'.$kind)); }
        self::assertSame(3,$j->count('voice_demo_operations'));self::assertTrue($j->host->state()['retention_applied']);self::assertTrue($j->host->state()['text_allowed']);
        $this->denies(fn()=>$j->delivery->send($segments[0]));$this->denies(fn()=>$j->heard());
        $j->accept(['kind'=>'text','utterance_id'=>'ut-2','text'=>'Continue in text.']);$text=$j->output();$j->delivery->display($text[0]);
        self::assertSame(1,$j->count('voice_demo_messages'));$this->denies(fn()=>$j->delivery->send($text[0]));
    }
    public function testCurrentFinal(): void
    {
        $j=$this->j;$receipt=$j->accept($this->events['final']);$j->drive();$this->nativeCounts(1,1);
        $row=$j->db->row('SELECT checkpoint,status FROM '.$j->db->table('runs').' WHERE id=:id',['id'=>$receipt->runId->value]);
        $checkpoint=json_decode(C::text($row,'checkpoint'),true);self::assertSame('completed',$row['status']);
        $json=new \AgenToolsPlatform\Examples\Voice\Json();
        self::assertSame($json->canonicalize($j->compiled->snapshot->toArray()),$json->canonicalize($checkpoint['accepted_snapshot']));self::assertSame('voice-utterance',$j->input->source->kind);
    }
    public function testConsentAllowed(): void
    {
        $j=$this->j;foreach (['recording','memory','export'] as $kind) { $j->host->operation($kind,'allowed:'.$kind); }
        $this->denies(fn()=>$j->host->operation('money','forbidden'));self::assertSame(3,$j->count('voice_demo_operations'));
        $segments=$this->finalOutput();$j->change(['allowed'=>false],'authorization:revoked');$this->denies(fn()=>$j->delivery->display($segments[0]));
        self::assertSame(0,$j->count('voice_demo_messages'));
    }
    public function testAllPositive(): void
    {
        $j=$this->j;$s=$this->finalOutput();foreach ($s as $segment) { $j->delivery->send($segment);$j->delivery->display($segment); }
        self::assertSame('Первый. Второй. Третий.',$j->heard());$this->nativeCounts(1,1);self::assertSame(1,$j->count('responses'));
        self::assertCount(3,$j->delivery->history($j->response));self::assertSame(1,$j->count('voice_demo_messages'));
    }
    public function testAllNegative(): void
    {
        $this->testInterim();$this->testStaleEpoch();
        $segments=$this->finalOutput();foreach ($segments as $s) { $this->j->delivery->display($s); }
        self::assertSame('',$this->j->heard());self::assertSame(0,$this->j->count('voice_demo_media'));
        $this->j->host->estimate=true;$this->j->delivery->send($segments[0]);self::assertSame('',$this->j->heard());
        self::assertSame('unknown',$this->j->ledger->state($segments[0])['state']);
        $this->j->delivery->interrupt($this->j->response);$this->denies(fn()=>$this->j->delivery->send($segments[1]));
    }
    public function testAllFault(): void
    {
        $this->testLostReceiptDedup();$j=$this->j;$s=$j->segments;$j->host->ttsOutage=true;
        $this->denies(fn()=>$j->delivery->send($s[1]));foreach ($s as $segment) { $j->delivery->display($segment); }
        $before=$j->accepted->toArray();$j->change(['media_epoch'=>2],'reconnect:tts');$j->accept($this->events['reconnect_final']);$j->drive();$j->delivery->reconnect($j->response);
        self::assertSame($before,$j->accepted->toArray());$j->host->ttsOutage=false;$j->delivery->send($s[1]);$j->delivery->interrupt($j->response);
        self::assertSame('Первый. Второй.',$j->heard());self::assertSame(3,$j->count('voice_demo_display'));$this->nativeCounts(1,1);
        $j->delivery->revoke($j->response);foreach (['recording','memory','export'] as $kind) { $this->denies(fn()=>$j->host->operation($kind,'after:'.$kind)); }
    }
    public function testLostReceiptDedup(): void
    {
        $j=$this->j;$s=$this->finalOutput();$j->host->loseReceipt=true;$j->delivery->send($s[0]);self::assertSame('unknown',$j->ledger->state($s[0])['state']);
        $this->denies(fn()=>$j->delivery->send($s[0]));$j->host->loseReceipt=false;
        $fresh=new IsolatedDatabase();$ledger=new Ledger(new C($fresh->pdo,$this->schema),$j->hasher);$delivery=new Coordinator($ledger,$j->host,$j->hasher,$j->host);
        $delivery->reconcile($s[0]);$delivery->send($s[0]);
        self::assertSame(1,$j->db->execute('SELECT sum(physical_plays) FROM '.$j->db->table('voice_demo_media'))->fetchColumn());self::assertSame('Первый.',$j->heard());$this->nativeCounts(1,1);
        $j->accept($this->events['text_fallback']);$j->drive();$j->delivery->display($s[0]);$j->delivery->display($s[0]);self::assertSame(1,$j->count('voice_demo_messages'));$this->nativeCounts(1,1);
    }
    public function testProviderChange(): void
    {
        $j=$this->j;$segments=$this->finalOutput();$j->delivery->send($segments[0]);$j->delivery->interrupt($j->response);
        $changed=$j->makePlan($j->deployment('scripted-voice/v2'));
        $this->denies(fn()=>$j->runtime()->advance($j->input->context,$j->checkpoint->lease,$changed));
        $this->denies(fn()=>$j->delivery->send($segments[1]));self::assertSame('Первый.',$j->heard());self::assertSame(1,$j->count('voice_demo_provider'));
        self::assertSame('scripted-voice/v1',$j->db->execute('SELECT deployment_ref FROM '.$j->db->table('voice_demo_provider'))->fetchColumn());
    }
    public function testContextChanges(): void
    {
        $j=$this->j;$j->accept($this->events['final']);$oldContext=$j->input->context;$oldPlan=$j->makePlan();$oldSnapshot=$j->compiled->snapshot;
        $oldText=$j->host->request('host:event')->transcript->textRef;
        $j->change(['subject_ref'=>'subject:2','locale'=>'en-US','context_revision'=>2,'policy_revision'=>'policy-2'],'context:changed');
        $this->denies(fn()=>$j->change(['context_revision'=>1],'invalid:downgrade'),\InvalidArgumentException::class);
        $this->denies(fn()=>$j->guard->assertCurrent($oldContext,$oldPlan));$j->accept($this->events['changed_context']);$j->drive();
        $this->denies(fn()=>$j->vault->get($j->input->context,$oldText));
        self::assertNotSame($oldSnapshot->contentHash->value,$j->compiled->snapshot->contentHash->value);
        $row=$j->db->row('SELECT checkpoint FROM '.$j->db->table('runs').' WHERE id=:run',['run'=>$j->accepted->runId->value]);$saved=json_decode(C::text($row,'checkpoint'),true);
        self::assertSame('subject:2',$saved['accepted_context']['subject_ref']['id']);
        $state=$j->db->row('SELECT state FROM '.$j->db->table('voice_demo_checkpoints').' WHERE id=\'context:changed\'');$state=json_decode(C::text($state,'state'),true);
        self::assertSame('en-US',$state['locale']);self::assertSame(2,$state['context_revision']);self::assertStringContainsString('en-US',$j->compiled->rendered);
    }
    public function testTenantReset(): void
    {
        $j=$this->j;$s=$this->finalOutput();$j->delivery->send($s[0]);$a=$j->accepted;
        $b=new Journey($this->database,$this->schema,'tenant-b');$b->accept($this->events['final']);$bs=$b->output();
        self::assertNotSame($a->turnId->value,$b->accepted->turnId->value);self::assertStringNotContainsString('tenant-a',$b->compiled->rendered);
        self::assertSame('',$b->heard());$this->denies(fn()=>$b->vault->get($b->input->context,$s[0]->speakableRef));
        self::assertSame(0,$b->count('voice_demo_media'));self::assertCount(3,$bs);
    }
    public function testRunnableExample(): void
    {
        $process=proc_open([PHP_BINARY,dirname(__DIR__,4).'/examples/platform/voice/run.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$error);$r=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        self::assertSame([1,1,1,3,1,3],[$r['turns'],$r['runs'],$r['model_calls'],$r['generated_segments'],$r['confirmed_segments'],$r['displayed_segments']]);
        self::assertSame('not_executed',$r['live_provider']);
        $this->extra['runnable_example']=$r;
    }
    public function testFinalConflict(): void
    {
        $this->finalOutput();$first=$this->j->accepted->toArray();
        $this->denies(fn()=>$this->j->accept(dict_event($this->events['final'],['revision'=>2,'text'=>'Changed final.'])),IntegrityConflict::class);
        self::assertSame($first,$this->j->accept($this->events['final'])->toArray());$this->nativeCounts(1,1);
    }
}

/** @param array<string,mixed> $event @param array<string,mixed> $changes @return array<string,mixed> */
function dict_event(array $event,array $changes): array { return array_replace($event,$changes); }
