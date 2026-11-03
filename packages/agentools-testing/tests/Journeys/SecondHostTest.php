<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Testing\Tests\Journeys;

use AgenToolsPlatform\Core\Common\{ContractViolation, TenantId};
use AgenToolsPlatform\Examples\SupportHost\{Direct, Host, Journey};
use AgenToolsPlatform\Examples\ReadJourney\{Jcs, SupportJourney};
use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Tests\Confirmation\Fixture;
use PHPUnit\Framework\TestCase;

final class SecondHostTest extends TestCase
{
    private IsolatedDatabase $db;
    private string $schema;
    protected function setUp(): void
    {
        $this->db = new IsolatedDatabase(); $this->schema = 'p3_'.bin2hex(random_bytes(12));
        SupportJourney::install($this->db, $this->schema);
    }
    protected function tearDown(): void { $this->db->dropSchema($this->schema); }
    private function host(): Host { return new Host(new CanonicalHasher(new Jcs()), new TenantId('tenant-a')); }
    private function journey(Host $host): Journey { return new Journey($host, new SupportJourney($this->db, $this->schema, 'tenant-a')); }
    private function drive(Journey $j): void
    {
        for ($i=0; $i<6; ++$i) { if (in_array($j->advance(), ['complete','completed'], true)) { return; } }
        self::fail('Runtime step bound exhausted');
    }
    public function testRuntimeAndDelivery(): void
    {
        $j=$this->journey($this->host()); $j->accept();
        self::assertStringContainsString('Ticket T-1 is open.', $j->runtime->compiled()->rendered);
        $this->drive($j);
        self::assertSame(1,$j->runtime->count('demo_reads'));
        self::assertSame(0,$j->runtime->count('demo_messages'));
        self::assertSame('delivered',$j->deliver()); self::assertSame(1,$j->runtime->count('demo_messages'));
        self::assertSame(2,$j->runtime->count('demo_transport_calls'));
    }
    public function testCurrentGuard(): void
    {
        $host=$this->host(); $j=$this->journey($host); $j->accept(); $host->emergency=true;
        try { $j->advance(); self::fail('Emergency must deny runtime'); } catch (ContractViolation) {}
        self::assertSame(0,$j->runtime->count('demo_transport_calls'));
    }
    public function testDeliveryLostAck(): void
    {
        $j=$this->journey($this->host()); $j->accept(); $this->drive($j); $j->runtime->loseDestinationAck=true;
        self::assertSame('unknown',$j->deliver()); self::assertSame(1,$j->runtime->count('demo_messages'));
        $fresh=$this->journey($this->host()); self::assertSame('delivered',$fresh->deliver());
        self::assertSame(1,$fresh->runtime->count('demo_messages')); self::assertSame(2,$fresh->runtime->count('demo_transport_calls'));
    }
    public function testOwnerRevokedBeforeWrite(): void
    {
        $f=new Fixture();
        try {
            $d=new Direct($this->host(),$f->composition,$f->owner); $s=$f->composition->submission();
            $preview=$d->prepare($s); $f->consent($preview); $d->approve($preview,$s); $grant=$d->commit($preview,$s);
            $f->composition->environment->db->execute('UPDATE '.$f->composition->environment->db->table('example_owner_permissions').' SET allowed=false');
            try { $d->execute($grant,$s); self::fail('Current owner revocation must deny write'); } catch (ContractViolation) {}
            self::assertSame(0,$f->count('example_owner_writes'));
        } finally { $f->close(); }
    }
    public function testInvalidContextProvider(): void
    {
        $j=$this->journey($this->host());
        $j->runtime->contextProvider = static fn () => ['invalid block'];
        $this->expectException(\InvalidArgumentException::class);
        $j->runtime->compiled();
    }
    public function testSourceHashConflict(): void
    {
        $j=$this->journey($this->host()); $j->accept(); $j->accept();
        $runtime=$j->runtime;
        $changed=new \AgenToolsPlatform\Core\Security\TrustedInput($runtime->context,$runtime->source,
            new \AgenToolsPlatform\Core\Common\InputPayload($runtime->ref('input:request-17'),
                new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.str_repeat('b',64))));
        try { (new \AgenToolsPlatform\Postgres\Inbox\IntakeRepository($runtime->environment))->accept($changed,$runtime->compiled()->snapshot);
            self::fail('Changed input under same source identity must conflict');
        } catch (\AgenToolsPlatform\Core\Common\IntegrityConflict) {}
        self::assertSame(1,$runtime->count('runs'));
    }
    public function testIndependentConfirmedAction(): void
    {
        $f=new Fixture();
        try {
            $host=$this->host();
            $f->composition->host->actor='other-actor';
            try { new Direct($host,$f->composition,$f->owner); self::fail('Different actor must be refused'); } catch (ContractViolation) {}
            $f->composition->host->actor='actor';
            $d=new Direct($host,$f->composition,$f->owner); $s=$f->composition->submission();
            $preview=$d->prepare($s);
            try { $d->approve($preview,$s); self::fail('Model text must not approve'); } catch (ContractViolation) {}
            self::assertSame(0,$f->count('example_owner_writes'));
            $f->consent($preview); $d->approve($preview,$s); $grant=$d->commit($preview,$s); $d->execute($grant,$s);
            self::assertSame(1,$f->count('example_owner_writes'));
            self::assertSame('succeeded',$f->composition->actions->status($grant->action,$f->composition->context())->outcome()?->value);
            $host->emergency=true;
            try { $d->execute($grant,$s); self::fail('Emergency must deny direct action'); } catch (ContractViolation) {}
            self::assertSame(1,$f->count('example_owner_writes'));
        } finally { $f->close(); }
    }
}
