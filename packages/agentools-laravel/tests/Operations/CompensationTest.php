<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Operations;

require_once __DIR__.'/Fixture.php';

use AgenToolsPlatform\Core\Actions\{ActionAccess, ActionApplication, ActionDefinition, ActionHandler, ActionPolicy, ActionRegistration, ActionRegistry, ActionRequest, ActionSubmission, EffectClass, InputSchemaValidator};
use AgenToolsPlatform\Core\Actions\Effects\LookupStatus;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Examples\ConfirmedWrite\{Composition, KeyMaterial};
use AgenToolsPlatform\Examples\DirectAction\Jcs;
use AgenToolsPlatform\Laravel\Operations\Operations;
use AgenToolsPlatform\Postgres\Actions\SubmissionLifecycle;
use AgenToolsPlatform\Postgres\Confirmation\{ScopedReplayStore, Service as Confirmation};
use AgenToolsPlatform\Postgres\Effects\{Ledger, Service};
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Tests\Effects\Owner;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class CompensationTest extends TestCase
{
    private Fixture $f;
    private ActionRegistry $registry;
    private ActionPolicy $policy;
    private ActionApplication $actions;
    private Confirmation $confirmation;
    private Operations $operations;
    private ActionDefinition $compensation;
    protected function setUp(): void
    {
        $this->compensation=$this->definition('document.compensate');
        $original=$this->definition('document.write',$this->compensation->ref);
        $this->f=new Fixture(null,$original); $c=$this->f->f->composition;
        $handler=new class implements ActionHandler { public function handlerId(): string { return 'documents.owner'; } };
        $this->registry=new ActionRegistry([new ActionRegistration($original,$handler),new ActionRegistration($this->compensation,$handler)]);
        $this->policy=new class($original,$this->compensation) implements ActionPolicy {
            public bool $denied=false;
            public function __construct(private ActionDefinition $original,private ActionDefinition $compensation) {}
            public function current(ExecutionContext $context): ActionAccess {
                $keys=[ActionAccess::key($this->original->ref),ActionAccess::key($this->compensation->ref)];
                return new ActionAccess($keys,$keys,$this->denied?[]:$keys,$keys,$keys,['documents.write']);
            }
        };
        $schema=new class implements InputSchemaValidator {
            public function assertValid(string $schemaJson,string $inputJson): void {
                if (!is_array(json_decode($inputJson,true,512,JSON_THROW_ON_ERROR))) { throw new ContractViolation(ErrorCode::InvalidInput); }
            }
        };
        $this->actions=new ActionApplication($this->registry,$this->policy,$c->host,$schema,new SubmissionLifecycle($c->environment,$this->registry,$this->policy,$schema));
        $keys=new KeyMaterial(str_repeat('s',32),str_repeat('e',32));
        $this->confirmation=new Confirmation($c->environment,$this->actions,$this->registry,$this->policy,$c->host,$keys,
            new ScopedReplayStore((new IsolatedDatabase())->pdo,$this->f->f->schema),$keys,$c->host);
        $this->operations=new Operations($this->f->audit,$this->actions,$this->registry,$this->policy,$this->confirmation);
    }
    protected function tearDown(): void { if (isset($this->f)) { $this->f->close(); } }
    private function definition(string $id,?\AgenToolsPlatform\Core\Profiles\DefinitionRef $compensation=null): ActionDefinition
    {
        return new ActionDefinition($id,'1','documents.owner',Composition::INPUT,'{"type":"object"}',EffectClass::Write,
            ['documents.write'],true,1000,1,0,3000,'native_key',null,'tenant-region','technical-ref-only','omit-payload',['actor','outcome'],$compensation,new Jcs());
    }
    private function submission(Ref $effect,string $key='compensating-operation'): ActionSubmission
    {
        $c=$this->f->f->composition; $base=$c->submission(key:$key); $r=$base->request;
        return new ActionSubmission(new ActionRequest($this->compensation->ref,new Ref($effect->tenant,'compensation:'.substr($effect->value,8)),null,
            $r->idempotencyKey,$r->inputRef,$r->inputHash,$r->context,$r->deadline,$c->hasher),$base->inputJson,$c->hasher,new Jcs());
    }
    /** @param callable():mixed $call */
    private function denies(callable $call): void {
        try { $call(); } catch (\Throwable $error) {
            self::assertNotSame('',$error->getMessage());
            self::assertStringNotContainsString('recipient',json_encode($error->getTrace(),JSON_THROW_ON_ERROR));
            return;
        }
        self::fail('Expected compensation denial');
    }

    public function testExplicitCompensationRequiresFreshConfirmation(): void
    {
        $original=$this->f->known(); $c=$this->f->f->composition;
        $state=(new \AgenToolsPlatform\Postgres\Confirmation\Journal($c->environment))->read($original->action);
        $effect=new Ref($c->host->tenant,array_key_first($state->effects)??throw new \LogicException('Missing original effect'));
        $submission=$this->submission($effect);
        $hash=$c->hasher->hash($c->host->tenant,'compensation-input',[$effect->toArray(),$submission->request->requestHash->value]);
        $command=$this->f->op('compensation_prepare',$original->action,$state->seq,$state->epoch,'prepare-compensation',payload:$hash);
        $preview=$this->operations->prepareCompensation($command,$effect,$submission);
        self::assertNotSame($original->action->value,$preview->binding->action->value);
        self::assertSame(1,$this->f->f->count('example_owner_writes'));
        self::assertSame($preview->token,$this->operations->prepareCompensation($command,$effect,$submission)->token);
        self::assertSame('awaiting_confirmation',$this->actions->status($preview->binding->action,$c->context())->receipt->wireStatus);
        $this->f->f->consent($preview); $this->confirmation->approve($preview->token,$submission);
        $grant=$this->confirmation->commit($preview->token,$submission);
        $owner=new Owner(new Connection((new IsolatedDatabase())->pdo,$this->f->f->schema),$c->environment->db);
        $ledger=new Ledger($c->environment,$grant,$submission,$this->registry,$this->policy);
        self::assertSame(LookupStatus::Succeeded,(new Service($ledger,$owner))->execute()->status);
        self::assertSame(2,$this->f->f->count('example_owner_writes'));
        self::assertNotSame($state->effects[$effect->value]->idempotencyKey,$ledger->request()->operationKey);
        self::assertSame('completed',$c->actions->status($original->action,$c->context())->receipt->wireStatus);
        $db=$c->environment->db;
        self::assertSame($effect->value,$db->execute('SELECT result->>\'original_effect\' FROM '.$db->table('operator_operation_receipts'))->fetchColumn());
    }

    public function testCompensationPolicyAndUnknownDenied(): void
    {
        [$original,$ledger,,$effects]=$this->f->unknown(); $c=$this->f->f->composition;
        $state=(new \AgenToolsPlatform\Postgres\Confirmation\Journal($c->environment))->read($original->action);
        $effect=new Ref($c->host->tenant,$ledger->request()->effectKey); $submission=$this->submission($effect);
        $hash=$c->hasher->hash($c->host->tenant,'compensation-input',[$effect->toArray(),$submission->request->requestHash->value]);
        $command=$this->f->op('compensation_prepare',$original->action,$state->seq,$state->epoch,'unknown-compensation',payload:$hash);
        $this->denies(fn ()=>$this->operations->prepareCompensation($command,$effect,$submission));
        $this->f->authority->allowed=false;
        $this->denies(fn ()=>$this->operations->prepareCompensation($command,$effect,$submission));
        self::assertSame(1,$this->f->f->count('example_owner_writes')); self::assertSame(1,$this->f->f->count('action_submissions'));
        $this->f->authority->allowed=true; $effects->reconcile();
        $state=(new \AgenToolsPlatform\Postgres\Confirmation\Journal($c->environment))->read($original->action);
        $fresh=$this->f->op('compensation_prepare',$original->action,$state->seq,$state->epoch,'fresh-proof',payload:$hash);
        $preview=$this->operations->prepareCompensation($fresh,$effect,$submission);
        $this->denies(fn ()=>$this->confirmation->commit($preview->token,$submission)); // Missing new consent; dated at1 consumes the attempt.
        self::assertSame(1,$this->f->f->count('example_owner_writes'));
        $other=$this->submission($effect,'owner-revoked-compensation');
        $otherHash=$c->hasher->hash($c->host->tenant,'compensation-input',[$effect->toArray(),$other->request->requestHash->value]);
        $otherCommand=$this->f->op('compensation_prepare',$original->action,$state->seq,$state->epoch,'owner-revoked',payload:$otherHash);
        $p=$this->operations->prepareCompensation($otherCommand,$effect,$other);
        $this->f->f->consent($p); $this->confirmation->approve($p->token,$other); $grant=$this->confirmation->commit($p->token,$other);
        $db=$c->environment->db; $db->execute('UPDATE '.$db->table('example_owner_permissions').' SET allowed=false');
        $owner=new Owner(new Connection((new IsolatedDatabase())->pdo,$this->f->f->schema),$db);
        $this->denies(fn ()=>(new Service(new Ledger($c->environment,$grant,$other,$this->registry,$this->policy),$owner))->execute());
        self::assertSame(1,$this->f->f->count('example_owner_writes'));
    }
}
