<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Definitions;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, InstanceId, IntegrityConflict, Ref, TenantId};
use AgenToolsPlatform\Laravel\Definitions\{DraftSettings, PostgresInstances, SessionBinder, Tone};
use AgenToolsPlatform\Postgres\Runtime\SessionRepository;
use PHPUnit\Framework\TestCase;

final class InstanceTest extends TestCase
{
    private Database $f;
    protected function setUp(): void { $this->f=new Database(); }
    protected function tearDown(): void { $this->f->close(); }
    public function testAssignmentsDraftsCasAndReplay(): void
    {
        $f=$this->f;$c=Fixture::context();$id=$f->id();
        $created=$f->instances->create($c,$f->ref('create'),$id,$f->definition->ref,$f->bundle());
        self::assertSame(1,$created->revision);self::assertSame(1,$f->instances->create($c,$f->ref('create'),$id,$f->definition->ref,$f->bundle())->revision);
        $assigned=$f->instances->assign($c,$f->ref('assign'),$id,1,$f->bundle(8));self::assertSame(2,$assigned->revision);
        $draft=$f->instances->draft($c,$f->ref('draft'),$id,2,new DraftSettings(Tone::Concise));self::assertSame(3,$draft->revision);
        self::assertSame(2,$f->instances->assign($c,$f->ref('assign'),$id,1,$f->bundle(8))->revision,'Replay changed after unrelated draft');
        self::assertSame(3,$f->instances->get($c,$id)->revision);
        try{$f->instances->assign($c,$f->ref('assign'),$id,1,$f->bundle(9));self::fail('Different hash accepted');}catch(IntegrityConflict){}
        try{$f->instances->assign($c,$f->ref('stale'),$id,1,$f->bundle(9));self::fail('Stale revision accepted');}
        catch(ContractViolation $e){self::assertSame(ErrorCode::OptimisticConflict,$e->errorCode);}
        self::assertSame(Tone::Neutral,$f->definition->defaults->tone);
    }
    public function testNewSessionsUseAssignmentAndExistingSessionKeepsPin(): void
    {
        $f=$this->f;$old=Fixture::context();$id=$f->id();
        $f->instances->create($old,$f->ref('create'),$id,$f->definition->ref,$f->bundle());
        $sessions=new SessionRepository($f->environment);$binder=new SessionBinder($f->instances,$f->registry,$f->current,$sessions);
        $first=$binder->bind($old,$id);self::assertSame(7,$first->bundle->version);self::assertArrayNotHasKey('draft',get_object_vars($first));
        $f->instances->assign($old,$f->ref('assign'),$id,1,$f->bundle(8));
        $f->instances->draft($old,$f->ref('draft'),$id,2,new DraftSettings(Tone::Concise));
        self::assertSame(7,$binder->bind($old,$id)->bundle->version);
        $new=Fixture::context(session:'session-2');self::assertSame(8,$binder->bind($new,$id)->bundle->version);
        self::assertSame('bundle:7',$sessions->get($old)?->bundleRef->value);self::assertSame('bundle:8',$sessions->get($new)?->bundleRef->value);
        $second=new InstanceId($id->tenant,'another');$f->instances->create($old,$f->ref('another-create'),$second,$f->definition->ref,$f->bundle());
        try{$binder->bind($old,$second);self::fail('Silent session retarget');}catch(IntegrityConflict){}
        $f->current->allowed=false;$this->expectException(ContractViolation::class);$binder->bind($old,$id);
    }
    public function testCurrentAuthorityAndTenantApplyEvenOnReplay(): void
    {
        $f=$this->f;$c=Fixture::context();$f->instances->create($c,$f->ref('create'),$f->id(),$f->definition->ref,$f->bundle());
        try{$f->instances->get($c,new InstanceId(new TenantId('beta'),'assistant'));self::fail('Foreign instance visible');}catch(ContractViolation){}
        $f->current->allowed=false;
        $this->expectException(ContractViolation::class);$f->instances->create($c,$f->ref('create'),$f->id(),$f->definition->ref,$f->bundle());
    }
    public function testCommitFaultRollsBackInstanceAndReceipt(): void
    {
        $f=$this->f;$c=Fixture::context();$f->instances->create($c,$f->ref('create'),$f->id(),$f->definition->ref,$f->bundle());
        $db=$f->environment->db;
        $db->execute('CREATE FUNCTION "'.$f->schema.'".reject_receipt() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'injected commit failure\' USING ERRCODE=\'40001\'; END $$');
        $db->execute('CREATE CONSTRAINT TRIGGER receipt_fault AFTER INSERT ON '.$db->table('assistant_mutations').' DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "'.$f->schema.'".reject_receipt()');
        try{$f->instances->draft($c,$f->ref('recover'),$f->id(),1,new DraftSettings(Tone::Concise));self::fail('Failed commit reported success');}
        catch(ContractViolation $e){self::assertSame(ErrorCode::OptimisticConflict,$e->errorCode);}
        self::assertSame(1,$f->instances->get($c,$f->id())->revision);
        self::assertNull($db->row('SELECT id FROM '.$db->table('assistant_mutations').' WHERE id=\'recover\''));
        $db->execute('DROP TRIGGER receipt_fault ON '.$db->table('assistant_mutations'));
        self::assertSame(2,$f->instances->draft($c,$f->ref('recover'),$f->id(),1,new DraftSettings(Tone::Concise))->revision);
        self::assertSame(2,$f->instances->draft($c,$f->ref('recover'),$f->id(),1,new DraftSettings(Tone::Concise))->revision);
    }
    public function testSchemaInstallIsVersionedAndConflictFails(): void
    {
        $db=$this->f->environment->db;PostgresInstances::install($db);
        $db->execute('UPDATE '.$db->table('assistant_schema_versions').' SET source_hash=\'different\'');
        $this->expectException(IntegrityConflict::class);PostgresInstances::install($db);
    }
}
