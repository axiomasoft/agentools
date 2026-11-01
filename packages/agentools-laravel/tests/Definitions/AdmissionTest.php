<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Definitions;

require_once dirname(__DIR__).'/Composition/bootstrap.php';

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref};
use AgenToolsPlatform\Laravel\Definitions\Registry as Definitions;
use AgenToolsPlatform\Laravel\Managed\{Entry, Executor, HostBindings};
use AgenToolsPlatform\Laravel\Plugins\{Contribution, FailurePolicy, Fragment, PluginHost, Registration, Registry};
use AgenToolsPlatform\Laravel\Tests\Composition\Fixture as Composition;
use PHPUnit\Framework\TestCase;

final class AdmissionTest extends TestCase
{
    private Composition $f;
    private CodePlugin $plugin;
    private Sink $sink;
    private Current $current;
    private ?PluginHost $wrapped=null;
    private bool $restrictTools=false;
    private bool $restrictRegions=false;
    protected function setUp(): void
    {
        $this->f=new Composition();$this->plugin=new CodePlugin();$this->sink=new Sink();$this->current=new Current();
        $manifest=Fixture::manifest(policy:FailurePolicy::Omit);$definition=Fixture::definition(plugins:[$manifest->ref]);
        $registry=new Registry([new Registration($manifest,$this->plugin)],$this->current);
        $this->f->app->scoped(HostBindings::class,function()use($definition,$registry):HostBindings{
            $p=$this->f->bindings();
            $this->plugin->contribution=new Contribution([new Fragment(new Ref($this->f->current()->tenant,'fragment'),Fixture::hash(),8,'injection:untrusted')]);
            $selected=$this->restrictTools?Fixture::definition(plugins:$definition->plugins,restrictions:new \AgenToolsPlatform\Laravel\Definitions\HardConstraints(['document.write'],['local'],[],32)):($this->restrictRegions?Fixture::definition(plugins:$definition->plugins,restrictions:new \AgenToolsPlatform\Laravel\Definitions\HardConstraints(['document.write'],['other-region'],['document.write'],32)):$definition);
            $host=new PluginHost($p->host,new Definitions([$selected]),$selected->ref,$this->current,$registry,$this->sink);$this->wrapped=$host;
            return new HostBindings($p->connection,$p->replayConnection,$p->schema,$p->hasher,$p->json,$p->authorization,$p->revisions,
                $p->registry,$p->policy,$p->schemas,$p->confirmation,$p->keys,$p->sealer,$p->clock,$p->transport,$p->budget,$p->guard,$p->payloads,$p->encrypted,$host);
        });
    }
    protected function tearDown(): void { $this->f->close(); }
    public function testEveryManagedAliasKeepsGuardsAndOneLoop(): void
    {
        $f=$this->f;
        foreach(Entry::cases() as $entry){
            $f->instance='plugins-'.$entry->value;$f->trace->events=[];
            $receipt=$f->app->make(Executor::class)->execute(new \AgenToolsPlatform\Laravel\Managed\Input('host:plugin',$entry));
            self::assertSame('alpha',$receipt->id->tenant->value);
            foreach(['auth','tenant/session','profile/data','context','deployment','budget','tools/actions','disclosure','delivery'] as $stage){self::assertContains($stage,$f->trace->observed());}
            self::assertSame($entry===Entry::DirectAction?0:1,count(array_filter($f->trace->observed(),static fn(string $s):bool=>$s==='model')));
            self::assertSame(0,$f->current()->count('example_owner_writes'));self::assertSame([],$this->sink->cache);self::assertNull($this->plugin->cache);
            if ($entry===Entry::Managed) {
                $f->current()->expire();
                $f->app->make(Executor::class)->execute(new \AgenToolsPlatform\Laravel\Managed\Input('host:plugin',$entry));
                self::assertSame(1,count(array_filter($f->trace->observed(),static fn(string $s):bool=>$s==='model')),'Replay started another loop');
            }
        }
        self::assertSame(18,$this->plugin->calls);self::assertSame(9,$this->sink->accepted);
    }
    public function testPluginAllowFlagsNeverOverrideMandatoryDenial(): void
    {
        $f=$this->f;$this->plugin->failure='optional';
        $f->app->instance('agentools.options',['plugin_allow'=>true,'classifier'=>'allow','skip_guards'=>true]);
        $f->trace->deny='budget';
        try{$f->app->make(Executor::class)->execute(new \AgenToolsPlatform\Laravel\Managed\Input('host:deny',Entry::Raw));self::fail('Plugin bypassed budget');}
        catch(ContractViolation $e){self::assertSame(ErrorCode::AccessDenied,$e->errorCode);}
        self::assertNotContains('model',$f->trace->observed());self::assertNotContains('delivery',$f->trace->observed());
        self::assertSame(0,$f->current()->count('example_owner_writes'));
        $f->trace->deny=null;$this->restrictTools=true;$f->instance='restricted';$f->trace->events=[];
        try{$f->app->make(Executor::class)->execute(new \AgenToolsPlatform\Laravel\Managed\Input('host:restricted',Entry::Stream));self::fail('Definition tool cap bypassed');}
        catch(ContractViolation $e){self::assertSame(ErrorCode::AccessDenied,$e->errorCode);}
        self::assertNotContains('model',$f->trace->observed());
        $this->restrictTools=false;$this->restrictRegions=true;$f->instance='wrong-region';$f->trace->events=[];
        try{$f->app->make(Executor::class)->execute(new \AgenToolsPlatform\Laravel\Managed\Input('host:region',Entry::Background));self::fail('Region cap bypassed');}
        catch(ContractViolation $e){self::assertSame(ErrorCode::PolicyConflict,$e->errorCode);}
        self::assertNotContains('model',$f->trace->observed());
    }
    public function testFragmentHashAndCurrentDataAccessCannotBeOmitted(): void
    {
        $f=$this->f;$this->sink->badHash=true;
        try{$f->app->make(Executor::class)->execute(new \AgenToolsPlatform\Laravel\Managed\Input('host:hash'));self::fail('Bad fragment hash accepted');}
        catch(\AgenToolsPlatform\Core\Common\IntegrityConflict){}
        self::assertNotContains('model',$f->trace->observed());
        $this->sink->badHash=false;$this->sink->allowed=false;
        try{$f->app->make(Executor::class)->execute(new \AgenToolsPlatform\Laravel\Managed\Input('host:acl'));self::fail('Source ACL bypassed');}
        catch(ContractViolation $e){self::assertSame(ErrorCode::AccessDenied,$e->errorCode);}
    }
    public function testSequentialTenantResetAndProductionDecoratorCheck(): void
    {
        $f=$this->f;$f->app->make(Executor::class)->execute(new \AgenToolsPlatform\Laravel\Managed\Input('host:alpha'));
        self::assertNull($this->plugin->cache);self::assertSame([],$this->sink->cache);self::assertNull($this->current->cache);
        self::assertNotNull($this->wrapped);
        try{HostBindings::assertProductionService($this->wrapped);self::fail('Decorator hid testing host');}catch(\LogicException){}
        $f->tenant='beta';$f->instance='beta';
        $receipt=$f->app->make(Executor::class)->execute(new \AgenToolsPlatform\Laravel\Managed\Input('host:beta',Entry::Background));
        self::assertSame('beta',$receipt->id->tenant->value);self::assertNull($this->plugin->cache);self::assertSame([],$this->sink->cache);self::assertNull($this->current->cache);
        self::assertStringNotContainsString('alpha',json_encode($receipt,JSON_THROW_ON_ERROR));
        $this->plugin->resetFailure=true;
        try{$f->app->make(Executor::class)->execute(new \AgenToolsPlatform\Laravel\Managed\Input('host:reset-fault'));self::fail('Plugin reset failure hidden');}
        catch(\AgenToolsPlatform\Laravel\Managed\LifecycleFailure){}
        self::assertSame([],$this->sink->cache);self::assertSame([],$f->trace->state);
        self::assertGreaterThanOrEqual(6,$this->sink->resets);
    }
}
