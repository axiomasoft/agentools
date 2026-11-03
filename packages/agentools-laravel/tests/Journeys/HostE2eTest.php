<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Tests\Journeys;

use AgenToolsPlatform\Conformance\HostE2e\SchoolOwner;
use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, IntegrityConflict, InputPayload, PayloadHash, TenantId};
use AgenToolsPlatform\Core\Security\TrustedInput;
use AgenToolsPlatform\Examples\ReadJourney\{Jcs, SupportJourney};
use AgenToolsPlatform\Examples\SupportHost\{Host, Journey};
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class HostE2eTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    protected function setUp(): void
    {
        $this->database=new IsolatedDatabase(); $this->schema='p3_'.bin2hex(random_bytes(12));
        SupportJourney::install($this->database,$this->schema);
    }
    protected function tearDown(): void { $this->database->dropSchema($this->schema); }
    private function runtime(string $tenant): SupportJourney { return new SupportJourney($this->database,$this->schema,$tenant); }
    private function drive(SupportJourney $j, ?SchoolOwner $school=null): void
    {
        for ($i=0;$i<6;++$i) { if ($school!==null) { $school->current(); }
            if (in_array($j->driveOnce(),['complete','completed'],true)) { return; } }
        self::fail('Runtime step bound exhausted');
    }
    public function testSchoolJourneyAndDuplicate(): void
    {
        $j=$this->runtime('school-a'); $school=new SchoolOwner($j); $school->attach();
        $first=$j->accept(); $second=$j->accept(); self::assertSame($first->toArray(),$second->toArray());
        self::assertStringContainsString('School helpdesk case', $j->compiled()->rendered);
        $this->drive($j,$school); self::assertSame(1,$j->count('turns')); self::assertSame(1,$j->count('runs'));
        self::assertSame(1,$j->count('demo_reads')); self::assertSame(0,$j->count('demo_messages'));
        $school->current(); self::assertSame('delivered',$j->delivery->deliver()); self::assertSame(1,$j->count('demo_messages'));
    }
    public function testSchoolRevocation(): void
    {
        $j=$this->runtime('school-a'); $school=new SchoolOwner($j); $school->attach(); $j->accept(); $school->allowed=false;
        try { $j->driveOnce(); self::fail('Revocation must stop context compilation'); } catch (ContractViolation) {}
        self::assertSame(0,$j->count('demo_transport_calls')); self::assertSame(0,$j->count('demo_reads'));
    }
    public function testSchoolRevocationBeforeModelIo(): void
    {
        $j=$this->runtime('school-a'); $school=new SchoolOwner($j); $school->attach(); $j->accept();
        $j->barrier=static function(string $point) use ($school): void {
            if ($point==='intent') { $school->allowed=false; }
            $school->current();
        };
        try { $j->driveOnce(); self::fail('Current owner revocation must stop model IO'); } catch (ContractViolation) {}
        self::assertSame(0,$j->count('demo_transport_calls'));
        self::assertSame(1,$j->count('demo_not_sent'));
    }
    public function testSupportJourneyAndHashConflict(): void
    {
        $j=$this->runtime('support-a'); $host=new Host(new CanonicalHasher(new Jcs()),new TenantId('support-a'));
        $journey=new Journey($host,$j); $journey->accept(); $journey->accept();
        self::assertStringContainsString('Ticket T-1 is open.',$j->compiled()->rendered);
        $changed=new TrustedInput($j->context,$j->source,new InputPayload($j->ref('input:request-17'),new PayloadHash('sha-256:'.str_repeat('b',64))));
        try { (new IntakeRepository($j->environment))->accept($changed,$j->compiled()->snapshot); self::fail('Changed duplicate must conflict'); }
        catch (IntegrityConflict) {}
        $this->drive($j); self::assertSame(1,$j->count('runs')); self::assertSame('delivered',$journey->deliver());
        self::assertSame(1,$j->count('demo_messages'));
    }
    public function testLostDeliveryReceipt(): void
    {
        $j=$this->runtime('support-a'); $journey=new Journey(new Host(new CanonicalHasher(new Jcs()),new TenantId('support-a')),$j);
        $journey->accept(); $this->drive($j); $j->loseDestinationAck=true;
        self::assertSame('unknown',$journey->deliver()); self::assertSame(1,$j->count('demo_messages'));
        $fresh=$this->runtime('support-a'); $resumed=new Journey(new Host(new CanonicalHasher(new Jcs()),new TenantId('support-a')),$fresh);
        self::assertSame('delivered',$resumed->deliver()); self::assertSame(1,$fresh->count('demo_messages'));
        self::assertSame(2,$fresh->count('demo_transport_calls'));
    }
    public function testDeliveryOutageBeforeDispatch(): void
    {
        $j=$this->runtime('school-a'); $school=new SchoolOwner($j); $school->attach(); $j->accept(); $this->drive($j,$school);
        $j->barrier=static function(string $point): void { if ($point==='delivery') { throw new \RuntimeException('synthetic destination outage'); } };
        try { $j->delivery->deliver(); self::fail('Outage must interrupt dispatch'); } catch (\RuntimeException $error) { self::assertSame('synthetic destination outage',$error->getMessage()); }
        self::assertSame(0,$j->count('demo_messages'));
        $row=$j->db->row('SELECT outcome FROM '.$j->db->table('delivery_intents')); self::assertSame('unknown',$row['outcome']??null);
        $j->barrier=null; $school->current(); self::assertSame('delivered',$j->delivery->deliver());
        self::assertSame(1,$j->count('demo_messages')); self::assertSame(2,$j->count('demo_transport_calls'));
    }
}
