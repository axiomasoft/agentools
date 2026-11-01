<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler\Tests;

use AgenToolsPlatform\Core\Actions\{ActionRequest, ActionSubmission};
use AgenToolsPlatform\Core\Actions\Confirmation\Decision;
use AgenToolsPlatform\Core\Actions\Effects\Strategy;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, UtcInstant};
use AgenToolsPlatform\Core\Runtime\ExchangeResult;
use AgenToolsPlatform\Examples\DirectAction\Jcs;
use AgenToolsPlatform\Postgres\Effects\Ledger;
use AgenToolsPlatform\Postgres\Runtime\RunRepository;
use AgenToolsPlatform\Scheduler\{Dispatch, Scanner};
use PHPUnit\Framework\TestCase;

final class RuntimeTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f = new Fixture(); }
    protected function tearDown(): void { $this->f->close(); }
    /** @return array{\AgenToolsPlatform\Scheduler\Schedule,string} */
    private function accepted(): array
    {
        $s = $this->f->schedule(); $this->f->store->register($s,$this->f->authority->context());
        self::assertCount(1,(new Scanner($this->f->store))->scan($s->id));
        return [$s,$this->f->store->handle($s,new UtcInstant('2026-11-01T12:00:00Z'))];
    }
    public function testManagedAdmissionAndLifecycle(): void
    {
        [$s,$handle] = $this->accepted(); $j = $this->f->runtime->current();
        self::assertSame(0,$j->count('write_provider')); self::assertSame(0,$j->count('write_admissions'));
        $result = (new Dispatch($this->f->store,$this->f->runtime->app))->run($handle);
        self::assertNotNull($result->acknowledgement); self::assertSame(1,$j->count('write_provider'));
        self::assertSame(1,$j->count('write_admissions')); self::assertSame(1,$j->count('write_messages'));
        self::assertSame(0,$j->count('example_owner_writes')); self::assertSame([],$this->f->authority->cache);
        self::assertGreaterThanOrEqual(2,$this->f->authority->resets);
        $saved = $this->f->store->resolve($handle,$j->context);
        self::assertSame('completed',(new RunRepository($j->environment))->get($j->context,$saved['run'])?->status->value);
        foreach (['auth','tenant/session','profile/data','context','deployment','budget','model','disclosure','delivery'] as $stage) {
            self::assertContains($stage,$this->f->runtime->trace->events);
        }
        self::assertSame('daily',$saved['schedule']->id->value);
    }
    public function testCurrentRevocationBeforeDispatch(): void
    {
        [$s,$handle] = $this->accepted(); $this->f->authority->allowed = false;
        try { (new Dispatch($this->f->store,$this->f->runtime->app))->run($handle); self::fail('Creation permission is not a dispatch grant'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::AccessDenied,$error->errorCode); }
        self::assertSame([],$this->f->authority->cache);
        $j = $this->f->runtime->current(); self::assertSame(0,$j->count('write_provider')); self::assertSame(0,$j->count('write_admissions'));
    }
    public function testCommonGuardDenialAndSourceSubstitution(): void
    {
        [$s,$handle] = $this->accepted(); $j = $this->f->runtime->current();
        $this->f->runtime->trace->deny = 'deployment';
        try { (new Dispatch($this->f->store,$this->f->runtime->app))->run($handle); self::fail('Mandatory deployment guard'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::AccessDenied,$error->errorCode); }
        self::assertSame(0,$j->count('write_provider')); self::assertSame(0,$j->count('write_admissions'));
        $this->f->runtime->trace->deny = null; $this->f->host->substitute = true;
        try { (new Dispatch($this->f->store,$this->f->runtime->app))->run($handle); self::fail('Host source substitution'); }
        catch (\Throwable $error) { self::assertInstanceOf(\AgenToolsPlatform\Core\Common\IntegrityConflict::class,$error); }
        self::assertSame(1,$j->count('turns')); self::assertSame(0,$j->count('write_provider'));
    }
    public function testMissingGuardAndAliasDenied(): void
    {
        [$s,$handle] = $this->accepted(); $j = $this->f->runtime->current();
        $this->f->host->forcedHandle = $handle;
        try {
            $this->f->ports->host->trusted(new \AgenToolsPlatform\Laravel\Managed\Input('other-handle',\AgenToolsPlatform\Laravel\Managed\Entry::Raw));
            self::fail('Raw alias cannot skip scheduled binding guard');
        } catch (\AgenToolsPlatform\Core\Common\IntegrityConflict $error) {
            self::assertStringContainsString('background ingress',$error->getMessage());
        }
        $this->f->host->substituteWork = true;
        try {
            $this->f->ports->host->compile($this->f->store->resolve($handle,$j->context)['input'],$j->plan()->bundle,\AgenToolsPlatform\Laravel\Managed\Entry::Background);
            self::fail('Compiled work cannot retarget the occurrence Run');
        } catch (ContractViolation $error) { self::assertSame(ErrorCode::IntegrityConflict,$error->errorCode); }
        $p = $this->f->ports;
        $this->f->ports = new \AgenToolsPlatform\Laravel\Managed\HostBindings($p->connection,$p->replayConnection,$p->schema,$p->hasher,$p->json,$p->authorization,
            $p->revisions,$p->registry,$p->policy,$p->schemas,$p->confirmation,$p->keys,$p->sealer,$p->clock,
            $p->transport,$p->budget,$p->guard,$p->payloads,$p->encrypted,$this->f->host);
        try { (new Dispatch($this->f->store,$this->f->runtime->app))->run($handle); self::fail('Missing scheduled host guard'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::PolicyConflict,$error->errorCode); }
        self::assertSame([],$this->f->authority->cache); self::assertSame(0,$j->count('write_provider'));
        self::assertSame(1,$j->count('turns'));
    }

    public function testStopPreservesUnknownAction(): void
    {
        $this->f->host->proposal = true; [$s,$handle] = $this->accepted(); $j = $this->f->runtime->current();
        (new Dispatch($this->f->store,$this->f->runtime->app))->run($handle);
        $plan = $j->activePlan; self::assertNotNull($plan);
        $row = $j->db->row('SELECT proposal_ref FROM '.$j->db->table('proposal_map').' WHERE tenant_id=:tenant AND run_id=:run',
            ['tenant'=>$j->tenant->value,'run'=>$plan->runId->value]); self::assertNotNull($row);
        $ref = \AgenToolsPlatform\Postgres\Runtime\Connection::text($row,'proposal_ref');
        $exchange = ExchangeResult::decode($plan,$j->vault->get($j->context,$j->ref($ref)),$j->hasher);
        $proposal = $exchange->proposals[0] ?? throw new \LogicException('Missing scheduled proposal');
        $submission = new ActionSubmission(new ActionRequest($proposal->definition,new Ref($j->tenant,$proposal->id->value),
            $proposal->id,new Ref($j->tenant,$proposal->id->value),$j->ref('proposal-input:'.$proposal->id->value),
            $proposal->inputHash,$j->context,$proposal->deadline,$j->hasher),$proposal->argumentsJson,$j->hasher,new Jcs());
        $preview = $j->confirmation->prepare($submission);
        $j->composition->host->consent = new Decision($j->ref('ui:scheduled'),$j->hasher->hash($j->tenant,'ui-proof',$preview->binding->hash->value),
            $j->context->identity->actorRef,$preview->binding->hash,'ui',$preview->binding->expiresAt);
        $j->confirmation->approve($preview->token,$submission); $grant = $j->confirmation->commit($preview->token,$submission);
        $ledger = new Ledger($j->environment,$grant,$submission,$j->composition->registry,$j->policy);
        $ledger->prepare('documents.owner',Strategy::NativeKey); $ledger->unknown('owner_receipt_unavailable');
        self::assertSame('unknown',$j->actions->status($grant->action,$j->context)->outcome()?->value);
        $this->f->store->stop($s->id,$j->context); $this->f->authority->time = new UtcInstant('2026-11-02T12:00:00Z');
        self::assertSame([],(new Scanner($this->f->store))->scan($s->id));
        $before = $this->f->store->resolve($handle,$j->context);
        try { (new Dispatch($this->f->store,$this->f->runtime->app))->run($handle); }
        catch (ContractViolation) { /* Existing awaiting/lease guard may defer the same accepted run. */ }
        self::assertSame('unknown',$j->actions->status($grant->action,$j->context)->outcome()?->value);
        self::assertSame('reconciliation_required',$j->actions->status($grant->action,$j->context)->receipt->wireStatus);
        self::assertSame($before['run']->value,$this->f->store->resolve($handle,$j->context)['run']->value);
        self::assertSame(1,$j->count('write_provider')); self::assertSame(0,$j->count('example_owner_writes'));
        Evidence::record('V2ALL-SCHED-FAULT',['same_run'=>true,'owner_outcome'=>'unknown','status'=>'reconciliation_required','provider_calls'=>1,'owner_writes'=>0]);
    }
    public function testTenantAndProductionBoundaries(): void
    {
        [$s,$handle] = $this->accepted(); $j = $this->f->runtime->current();
        $beta = new \AgenToolsPlatform\Conformance\WriteJourney\Journey(new \AgenToolsPlatform\Postgres\Tests\IsolatedDatabase(),$this->f->runtime->schema,'beta','input-1');
        $authority = new HostAuthority($beta); $store = new \AgenToolsPlatform\Scheduler\Store($beta->environment,$authority);
        $c = $beta->context;
        $other = new \AgenToolsPlatform\Scheduler\Schedule($beta->ref('daily'),1,$beta->source->instance,$c->session,$c->identity->actorRef,null,$c->purpose,
            $beta->ref('template:daily'),'UTC','12:00:00',new UtcInstant('2026-11-01T00:00:00Z'),new UtcInstant('2027-01-01T00:00:00Z'),'choose-first','skip','catchup');
        $store->register($other,$c); self::assertCount(1,(new Scanner($store))->scan($other->id));
        self::assertNotSame($handle,$store->handle($other,new UtcInstant('2026-11-01T12:00:00Z')));
        try { $store->resolve($handle,$c); self::fail('Cross-tenant occurrence'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::AccessDenied,$error->errorCode); }
        self::assertSame(1,$j->count('scheduler_occurrences')); self::assertSame(1,$beta->count('scheduler_occurrences'));
        try { \AgenToolsPlatform\Laravel\Managed\HostBindings::assertProductionService($this->f->ports->host); self::fail('Nested testing host'); }
        catch (\LogicException $error) { self::assertStringContainsString('Production composition refuses',$error->getMessage()); }
        $this->f->runtime->app->instance('env','production');
        $this->expectException(\LogicException::class);
        $this->f->runtime->app->make(\AgenToolsPlatform\Laravel\Managed\HostBindings::class);
    }
}
