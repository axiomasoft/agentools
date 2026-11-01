<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Conformance\WriteJourney;

use AgenTools\Action\{ActionReducer, JournalHashChain};
use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Common\{ContractViolation, IntegrityConflict, Outcome, Ref};
use AgenToolsPlatform\Postgres\Actions\{ActionRepository, Projection};
use AgenToolsPlatform\Postgres\Confirmation\Journal;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class WriteJourneyTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    private Journey $j;
    protected function setUp(): void
    {
        $this->database=new IsolatedDatabase(); $this->schema='p3_'.bin2hex(random_bytes(12));
        Journey::install($this->database,$this->schema); $this->j=new Journey($this->database,$this->schema);
    }
    protected function tearDown(): void { if (isset($this->database,$this->schema)) { if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); } $this->database->dropSchema($this->schema); } }
    /** @param callable():mixed $operation @param class-string<\Throwable> $type */
    private function denies(callable $operation,string $type=\Throwable::class): void
    {
        try { $operation(); } catch (\Throwable $e) { self::assertTrue(is_a($e,$type),'Expected '.$type.', got '.$e::class.': '.$e->getMessage()); return; }
        self::fail('Expected write guard denial');
    }

    public function testConfirmedWriteJourney(): void
    {
        $j=$this->j; self::assertSame('completed',$j->drive()); $j->deliver();
        self::assertSame(1,$j->count('turns')); self::assertSame(1,$j->count('runs'));
        self::assertSame(1,$j->count('action_submissions')); self::assertSame(1,$j->count('proposal_map'));
        self::assertSame(1,$j->count('example_owner_writes')); self::assertSame(1,$j->count('write_messages'));
        self::assertSame(2,$j->count('write_provider')); self::assertSame(2,$j->count('usage_versions'));
        $proposal=$j->proposal();
        self::assertSame(Outcome::Succeeded,$j->actions->status($proposal->actionId,$j->context)->outcome());
        self::assertSame('completed',$j->drive()); $j->deliver();
        self::assertSame(1,$j->count('example_owner_writes')); self::assertSame(2,$j->count('write_provider'));
    }

    public function testDirectAndAssistantShareGuardsAndIdentity(): void
    {
        $j=$this->j; $j->accept(); self::assertSame('waiting_action',$j->advance()->stage());
        $proposal=$j->proposal(); $request=$j->submission($proposal);
        $direct=$j->actions->submit($request)->view;
        self::assertSame($proposal->actionId->value,$direct->receipt->actionId->value);
        $j->write();
        $key=$j->db->execute('SELECT owner_operation_key FROM '.$j->db->table('effect_intents'))->fetchColumn();
        self::assertSame($key,$j->db->execute('SELECT operation_key FROM '.$j->db->table('example_owner_writes'))->fetchColumn());
        $j->db->execute('UPDATE '.$j->db->table('write_controls').' SET tool_allowed=false');
        $this->denies(fn ()=>$j->actions->submit($request),ContractViolation::class);
        $this->denies(fn ()=>(new Dispatcher($j))->submit($j->context,$proposal),ContractViolation::class);
        self::assertSame(1,$j->count('example_owner_writes'));
    }

    public function testRevokeAndEmergencyBeforeOwnerWrite(): void
    {
        $j=$this->j; $j->accept(); $j->advance(); $grant=$j->confirm();
        $j->db->execute('UPDATE '.$j->db->table('example_owner_permissions').' SET allowed=false');
        $this->denies(fn ()=>$j->write()); self::assertSame(0,$j->count('example_owner_writes'));
        self::assertSame(Outcome::Failed,$j->actions->status($grant->action,$j->context)->outcome());
        $j->db->execute('UPDATE '.$j->db->table('write_controls').' SET emergency=true');
        $this->denies(fn ()=>$j->actions->status($grant->action,$j->context),ContractViolation::class);
        self::assertSame(0,$j->count('write_messages'));
    }

    public function testF2OwnerCommitLocalJournalFailure(): void
    {
        $j=$this->j; $j->accept(); $j->advance(); $grant=$j->confirm();
        $pdo=$this->database->pdo; $schema='"'.$this->schema.'"';
        $pdo->exec('CREATE FUNCTION '.$schema.'.f2_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            IF NEW.kind=\'effect_confirmed\' THEN RAISE EXCEPTION \'owner commit then local journal failure\'; END IF; RETURN NULL; END $$');
        $pdo->exec('CREATE CONSTRAINT TRIGGER f2_fault AFTER INSERT ON '.$schema.'.agentools_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION '.$schema.'.f2_fault()');
        // Exercise the same actual example owner and deferred journal failure as saved P5.2 F2.
        $example=new \AgenToolsPlatform\Examples\ConfirmedWrite\Owner($j->composition,(new IsolatedDatabase())->pdo,$this->schema);
        try { $this->denies(fn ()=>$example->write($grant,$j->submission()),\AgenTools\Effects\EffectReconciliationRequired::class); }
        finally { $pdo->exec('DROP TRIGGER f2_fault ON '.$schema.'.agentools_events'); }
        self::assertSame(1,$j->count('example_owner_writes'));
        self::assertSame('reconciliation_required',$j->actions->status($grant->action,$j->context)->receipt->wireStatus);
        self::assertSame(Outcome::Unknown,$j->actions->status($grant->action,$j->context)->outcome());
        self::assertSame('unknown',$j->db->execute('SELECT outcome FROM '.$j->db->table('effect_intents'))->fetchColumn());
        $pdo->exec('CREATE FUNCTION '.$schema.'.forbid_owner_write() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'owner replay forbidden\'; END $$');
        $pdo->exec('CREATE TRIGGER forbid_owner_write BEFORE INSERT OR UPDATE OR DELETE ON '.$schema.'.example_owner_writes FOR EACH ROW EXECUTE FUNCTION '.$schema.'.forbid_owner_write()');
        $example->reconcile($grant,$j->submission()); $example->reconcile($grant,$j->submission());
        self::assertSame(1,(int)$j->db->execute('SELECT count(*) FROM '.$j->db->table('agentools_events')." WHERE kind='effect_confirmed'")->fetchColumn());
        self::assertSame('completed',$j->drive()); $j->deliver(); self::assertSame(1,$j->count('example_owner_writes'));
    }

    public function testTenantInstanceSwitchAndHashConflict(): void
    {
        $j=$this->j; $j->accept(); $j->accept();
        $otherInstance=new Journey($this->database,$this->schema,'tenant-a','assistant-v2'); $otherInstance->accept();
        $otherTenant=new Journey($this->database,$this->schema,'tenant-b','assistant'); $otherTenant->accept();
        self::assertNotSame($j->source->key($j->hasher)->value,$otherInstance->source->key($j->hasher)->value);
        self::assertNotSame($j->run->value,$otherTenant->run->value);
        self::assertSame(2,$j->count('turns')); self::assertSame(1,$otherTenant->count('turns'));
        $this->denies(fn ()=>$j->accept('{"recipient":"changed","document":"d1","amount":100}'),IntegrityConflict::class);
        $this->denies(fn ()=>(new \AgenToolsPlatform\Postgres\Runtime\RunRepository($j->environment))->get($j->context,$otherTenant->run),ContractViolation::class);
        self::assertSame(0,$j->count('example_owner_writes'));
    }
}
