<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Composition;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Laravel\Managed\{Entry, Executor, Input};
use PHPUnit\Framework\TestCase;

final class ManagedTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f = new Fixture(); }
    protected function tearDown(): void { $this->f->close(); }

    public function testEveryEntrySharesMandatoryAdmission(): void
    {
        $f = $this->f;
        $previews = 0; $calls = 0;
        foreach (Entry::cases() as $entry) {
            $f->instance = 'entry-'.$entry->value;
            $f->trace->events = [];
            $receipt = $f->app->make(Executor::class)->execute(new Input('host:'.$entry->value,$entry));
            self::assertSame('alpha',$receipt->id->tenant->value);
            self::assertNotNull($receipt->acknowledgement);
            $events = $f->trace->observed();
            $required = ['auth','tenant/session','profile/data','context','deployment','budget'];
            if ($entry !== Entry::DirectAction) { $required[] = 'model'; }
            array_push($required,'tools/actions','disclosure','delivery');
            $position = -1;
            foreach ($required as $stage) {
                $next = array_search($stage,array_slice($events,$position+1),true);
                self::assertNotFalse($next, $entry->value.': '.$stage.' in '.json_encode($events));
                $position += $next+1;
            }
            self::assertSame(0,$f->current()->count('example_owner_writes'));
            self::assertSame(++$previews,$f->current()->count('confirmation_previews'));
            if ($entry !== Entry::DirectAction) { ++$calls; }
            self::assertSame($calls,$f->current()->count('write_provider'));
            self::assertSame([],$f->trace->state);
            self::assertNotEmpty($events);
            self::assertSame('reset',array_pop($events));
        }
        self::assertSame(8,$f->factories);
    }

    public function testAcceptedSourceCannotStartSecondLoop(): void
    {
        $f = $this->f;
        $executor = $f->app->make(Executor::class);
        $executor->execute(new Input('host:stable'));
        $j = $f->current();
        $j->expire();
        $executor->execute(new Input('host:stable'));
        self::assertSame(1,$f->current()->count('turns'));
        self::assertSame(1,$f->current()->count('runs'));
        self::assertSame(1,$f->current()->count('write_provider'));
        self::assertSame(1,$f->current()->count('action_submissions'));
        self::assertSame(1,$f->current()->count('confirmation_previews'));
        self::assertSame(0,$f->current()->count('example_owner_writes'));
        self::assertSame(1,count(array_filter($f->trace->events,fn ($stage) => $stage === 'model')));
        self::assertSame([],$f->trace->state);
    }

    public function testGuardDenialStopsLaterStages(): void
    {
        $f = $this->f;
        foreach (['auth','tenant/session','profile/data','context','deployment','budget','tools/actions','disclosure'] as $deny) {
            $f->instance = 'deny-'.str_replace('/','-',$deny);
            $f->trace->deny = $deny;
            $f->trace->events = [];
            try { $f->app->make(Executor::class)->execute(new Input('host:denied')); self::fail('Guard was bypassed: '.$deny); }
            catch (ContractViolation $error) { self::assertSame(ErrorCode::AccessDenied,$error->errorCode); }
            self::assertNotContains('delivery',$f->trace->events);
            if (in_array($deny,['auth','tenant/session','profile/data','context','deployment','budget'],true)) {
                self::assertNotContains('model',$f->trace->events);
            }
            self::assertSame([],$f->trace->state);
            self::assertSame(0,$f->current()->count('example_owner_writes'));
        }
    }

    public function testUntrustedToolAndPluginFlagsGrantNothing(): void
    {
        $f = $this->f;
        // Untrusted data/DB settings are stored as data. They never become pipeline switches.
        $f->app->instance('agentools.options',['skip_guards'=>true,'classifier'=>'allow','plugin_allow'=>true,
            'secret_ref'=>'alpha:secret','handler'=>'arbitrary\\Class','routes'=>true]);
        $f->trace->forbidden = true;
        try { $f->app->make(Executor::class)->execute(new Input('host:injection',Entry::Raw)); self::fail('Forbidden tool accepted'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::InvocationUnknown,$error->errorCode); }
        self::assertSame(0,$f->current()->count('action_submissions'));
        self::assertSame(0,$f->current()->count('confirmation_previews'));
        self::assertSame(0,$f->current()->count('example_owner_writes'));
        self::assertContains('profile/data',$f->trace->events);
        self::assertContains('disclosure',$f->trace->events);
        self::assertNotContains('delivery',$f->trace->events);
        self::assertCount(0,$f->app->make(\Illuminate\Routing\Router::class)->getRoutes()->getRoutes());
    }

    public function testRevocationBeforeDisclosurePreventsDelivery(): void
    {
        $f = $this->f; $f->trace->revokeAtDisclosure = true;
        try { $f->app->make(Executor::class)->execute(new Input('host:revoked',Entry::Stream)); self::fail('Revocation ignored'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::AccessDenied,$error->errorCode); }
        self::assertContains('model',$f->trace->events);
        self::assertNotContains('delivery',$f->trace->events);
        self::assertSame(0,$f->current()->count('write_messages'));
        self::assertSame([],$f->trace->state);
    }

    public function testUnknownBudgetCannotReachModelOrAction(): void
    {
        $f = $this->f; $f->trace->budgetUnknown = true;
        foreach ([Entry::Managed,Entry::DirectAction] as $entry) {
            $f->instance = 'unknown-'.$entry->value; $f->trace->events = [];
            try { $f->app->make(Executor::class)->execute(new Input('host:budget',$entry)); self::fail('Unknown admitted'); }
            catch (ContractViolation $error) { self::assertSame(ErrorCode::AdmissionDenied,$error->errorCode); }
            self::assertNotContains('model',$f->trace->events);
            self::assertNotContains('tools/actions',$f->trace->events);
            self::assertNotContains('delivery',$f->trace->events);
        }
    }

    public function testUnknownDeliveryIsNotZeroOrSuccess(): void
    {
        $f = $this->f; $f->trace->unknownDelivery = true;
        $receipt = $f->app->make(Executor::class)->execute(new Input('host:unknown',Entry::Voice));
        self::assertNull($receipt->acknowledgement);
        self::assertContains('delivery:unknown',$f->trace->decisions);
        self::assertSame(1,$f->current()->count('write_messages'));
    }

    public function testCrossTenantGuardEvidenceDenied(): void
    {
        $f = $this->f;
        foreach (['foreignAuthentication','foreignDecision'] as $flag) {
            $f->trace->{$flag} = true;
            try { $f->app->make(Executor::class)->execute(new Input('host:foreign')); self::fail('Foreign decision accepted'); }
            catch (ContractViolation|\AgenToolsPlatform\Core\Common\IntegrityConflict) {}
            self::assertNotContains('profile/data',$f->trace->events);
            self::assertNotContains('model',$f->trace->events);
            self::assertSame([],$f->trace->state);
            $f->trace->{$flag} = false;
        }
    }

    public function testProductionAndTestingProfileFailClosed(): void
    {
        $f = $this->f;
        foreach ([['production',false],['production',true]] as [$environment,$testing]) {
            $f->app->instance('env',$environment); $f->app->instance('agentools.testing',$testing);
            try { $f->app->make(Executor::class)->execute(new Input('host:production')); self::fail('Fake production accepted'); }
            catch (ContractViolation $error) { self::assertSame(ErrorCode::PolicyConflict,$error->errorCode); }
            try { $f->app->make(\AgenToolsPlatform\Core\Models\ModelTransport::class); self::fail('Fake production port resolved'); }
            catch (\Throwable $error) { self::assertInstanceOf(\LogicException::class,$error); self::assertStringContainsString($testing ? 'Testing composition' : 'Production composition',$error->getMessage()); }
            self::assertNotContains('auth',$f->trace->events);
            self::assertSame([],$f->trace->state);
        }
    }
}
