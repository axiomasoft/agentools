<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Effects;

require_once dirname(__DIR__,3).'/agentools-core/tests/Actions/Confirmation/PostgresTest.php';

use AgenToolsPlatform\Core\Actions\Confirmation\ConfirmedAction;
use AgenToolsPlatform\Core\Common\Outcome;
use AgenToolsPlatform\Postgres\Effects\Ledger;
use AgenToolsPlatform\Tests\Confirmation\{Fixture, Worker};
use PHPUnit\Framework\TestCase;

final class CrashTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f=new Fixture(); }
    protected function tearDown(): void { if (isset($this->f)) { $this->f->close(); } }

    public function testSigkillAfterOwnerCommit(): void
    {
        $c=$this->f->composition; $preview=$this->f->approve();
        $grant=new ConfirmedAction($preview->binding->action,$preview->proposal,$preview->binding->hash,
            $preview->binding->version+2,$preview->binding->epoch);
        $pdo=$this->f->database->pdo; $schema='"'.$this->f->schema.'"';
        $key=random_int(100000,2000000000);
        $pdo->query('SELECT pg_advisory_lock('.$key.')');
        $pdo->exec('CREATE FUNCTION '.$schema.'.owner_crash_barrier() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            IF NEW.kind=\'effect_confirmed\' THEN PERFORM pg_advisory_xact_lock('.$key.'); END IF; RETURN NEW; END $$');
        $pdo->exec('CREATE TRIGGER owner_crash_barrier BEFORE INSERT ON '.$schema.'.agentools_events FOR EACH ROW EXECUTE FUNCTION '.$schema.'.owner_crash_barrier()');
        $worker=new Worker($this->f->schema,'owner-crash');
        try {
            $worker->go(); $this->waitLocks(1);
            // The barrier is reachable only AFTER owner Connection::transaction committed.
            self::assertSame(1,$this->f->count('example_owner_writes'));
            $view=$c->actions->status($grant->action,$c->context());
            self::assertSame('applying',$view->receipt->wireStatus); self::assertSame(Outcome::Unknown,$view->outcome());
            $worker->kill(); self::assertNotSame(0,$worker->finish()['exit']);
        } finally { $pdo->query('SELECT pg_advisory_unlock('.$key.')'); }
        $pdo->exec('DROP TRIGGER owner_crash_barrier ON '.$schema.'.agentools_events');
        $pdo->exec('CREATE FUNCTION '.$schema.'.no_owner_replay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'duplicate owner effect\'; END $$');
        $pdo->exec('CREATE TRIGGER no_owner_replay BEFORE INSERT OR UPDATE OR DELETE ON '.$schema.'.example_owner_writes FOR EACH ROW EXECUTE FUNCTION '.$schema.'.no_owner_replay()');
        $fresh=new Fixture($this->f->schema);
        $fresh->owner->reconcile($grant,$fresh->composition->submission());
        $fresh->owner->reconcile($grant,$fresh->composition->submission());
        self::assertSame(1,$this->f->count('example_owner_writes'));
        self::assertSame(Outcome::Succeeded,$c->actions->status($grant->action,$c->context())->outcome());
        $db=$c->environment->db;
        self::assertSame(1,(int)$db->execute('SELECT count(*) FROM '.$db->table('agentools_events')." WHERE kind='effect_confirmed'")->fetchColumn());
    }

    public function testCompetingRecovery(): void
    {
        $c=$this->f->composition; $p=$this->f->approve();
        $grant=$c->confirmation->commit($p->token,$c->submission());
        $ledger=new Ledger($c->environment,$grant,$c->submission(),$c->registry,$c->policy);
        $request=$ledger->prepare('example.documents',\AgenToolsPlatform\Core\Actions\Effects\Strategy::NativeKey);
        $db=$c->environment->db;
        $db->execute('INSERT INTO '.$db->table('example_owner_writes').' VALUES (:tenant,:key,:hash,CAST(:payload AS jsonb))',
            ['tenant'=>$request->action->tenant->value,'key'=>$request->operationKey,'hash'=>$request->requestHash->value,'payload'=>$c->submission()->inputJson]);
        $ledger->unknown('owner_response_lost');
        $pdo=$this->f->database->pdo;
        $pdo->beginTransaction();
        $pdo->exec('LOCK TABLE '.$db->table('action_current').' IN ACCESS EXCLUSIVE MODE');
        $workers=[];
        try {
            foreach (['a','b'] as $name) { $workers[]=new Worker($this->f->schema,'recover-'.$name); }
            foreach ($workers as $worker) { $worker->go(); }
            $this->waitLocks(2);
        } finally { $pdo->rollBack(); }
        foreach ($workers as $worker) { $result=$worker->finish(); self::assertSame(0,$result['exit'],$result['stderr']); }
        self::assertSame(1,$this->f->count('example_owner_writes'));
        self::assertSame(1,(int)$db->execute('SELECT count(*) FROM '.$db->table('agentools_events')." WHERE kind='effect_confirmed'")->fetchColumn());
    }

    private function waitLocks(int $count): void
    {
        $deadline=hrtime(true)+10_000_000_000; $waiting=0;
        do {
            $stmt=$this->f->database->pdo->prepare("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND application_name LIKE :prefix AND wait_event_type='Lock'");
            $stmt->execute(['prefix'=>'confirmation_'.$this->f->schema.'%']); $waiting=(int)$stmt->fetchColumn();
            if ($waiting===$count) { break; } usleep(1000);
        } while (hrtime(true)<$deadline);
        self::assertSame($count,$waiting,'Real PostgreSQL barrier must be reached');
    }
}
