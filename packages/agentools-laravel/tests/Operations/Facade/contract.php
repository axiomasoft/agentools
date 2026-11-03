<?php

declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
require_once dirname(__DIR__,4).'/agentools-core/tests/Actions/Fixtures.php';

use AgenToolsPlatform\Laravel\Operations\{Command,Finding};
use AgenToolsPlatform\Core\Common\{Ref,TenantId,ContractViolation};
use AgenToolsPlatform\Tests\{Harness as H,TestHost};
use AgenToolsPlatform\Tests\Actions\Fixtures as F;
use AgenToolsPlatform\Laravel\Console;

$tenant=new TenantId('ops-tenant'); $context=F::context(new TestHost($tenant)); $id=new Ref($tenant,'operation');
$cases=[
    'operations.command-scope-bounds'=>static function () use ($context,$id): void {
        $default=new Command($context,$id,'recover','run','triage'); H::check(!$default->apply && $default->limit===50);
        foreach ([0,101] as $limit) { H::rejects(static fn()=>new Command($context,$id,'recover','run','triage',limit:$limit),InvalidArgumentException::class); }
        H::rejects(static fn()=>new Command($context,$id,'retry-everything','*','triage'),InvalidArgumentException::class);
        H::rejects(static fn()=>new Command($context,$id,'prune','','triage'),InvalidArgumentException::class);
        H::rejects(static fn()=>new Command($context,$id,'doctor','','triage',true),InvalidArgumentException::class);
        H::rejects(static fn()=>new Command($context,$id,'recover','run','user full text'),InvalidArgumentException::class);
        H::rejects(static fn()=>new Command($context,new Ref(new TenantId('other'),'operation'),'recover','run','triage'),ContractViolation::class);
        H::rejects(static fn()=>new Command($context,$id,'relay','key','triage',resource:'effect'),InvalidArgumentException::class);
    },
    'operations.redacted-findings'=>static function (): void {
        H::rejects(static fn()=>new Finding('secret','ready','password=raw'),InvalidArgumentException::class);
        H::rejects(static fn()=>new Finding('probe','success','verified'),InvalidArgumentException::class);
        H::rejects(static fn()=>new Finding('probe','unknown','unavailable','raw text secret'),InvalidArgumentException::class);
        H::check((new Finding('probe','unknown','unavailable'))->metadata()['status']==='unknown');
    },
    'operations.opt-in-command-definitions'=>static function (): void {
        foreach ([Console\Doctor::class,Console\InspectRun::class,Console\Recover::class,Console\Reconcile::class,Console\Relay::class,Console\Prune::class] as $class) {
            $c=new $class(); $d=$c->getDefinition();
            H::check($d->hasArgument('operation_ref') && !$d->hasOption('tenant') && !$d->hasOption('actor'));
            H::check($d->getOption('limit')->getDefault()==='50' && $d->getOption('apply')->getDefault()===false);
        }
    },
];
H::run($cases,'contract-scripted','contract-scripted');
