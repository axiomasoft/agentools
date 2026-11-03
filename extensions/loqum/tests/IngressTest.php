<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Tests;

use AgenToolsPlatform\Core\Common\{IntegrityConflict, PayloadHash, SessionId, TenantId};
use AgenToolsPlatform\Loqum\Ingress\{Boundary, MediaState, Rejection};
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Runtime\SessionRepository;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Tests\Runtime\Fixture as PgFixture;
use PHPUnit\Framework\TestCase;

/** Real shared intake; synthetic authenticated media/host owner, no acoustic/provider qualification. */
final class IngressTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    private Fixture $voice;
    private PgFixture $pg;
    private IntakeRepository $intake;

    protected function setUp(): void
    {
        $this->database = new IsolatedDatabase();
        $this->schema = 'p3_'.bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema);
        (new Migrator($this->database->pdo,$this->schema))->migrate();
        $this->voice = new Fixture();
        $this->pg = new PgFixture($this->database->pdo,$this->schema,$this->voice->tenant);
        $context = $this->voice->boundary->open('host:input')->input->context;
        (new SessionRepository($this->pg->environment))->bind($context,$this->pg->binding($context));
        $this->intake = new IntakeRepository($this->pg->environment);
    }

    protected function tearDown(): void
    {
        if (isset($this->database,$this->schema)) {
            if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); }
            $this->database->dropSchema($this->schema);
        }
    }

    private function accept(): \AgenToolsPlatform\Core\Common\AcceptedTurn
    {
        $envelope = $this->voice->boundary->open('host:input');
        $this->voice->boundary->assertCurrent($envelope);
        return $this->intake->accept($envelope->input,$this->pg->snapshot($envelope->input->context));
    }

    private function counts(int $accepted): void
    {
        foreach (['turns','runs','inbox','outbox','invocation_intents','proposal_map','effect_intents','delivery_intents'] as $table) {
            $count = $this->database->pdo->query('SELECT count(*) FROM "'.$this->schema.'"."'.$table.'"')->fetchColumn();
            self::assertSame(in_array($table,['turns','runs','inbox','outbox'],true) ? $accepted : 0,$count,$table);
        }
    }

    public function testReconnectLostReceipt(): void
    {
        $first = $this->accept();
        self::assertSame($first->toArray(),$this->accept()->toArray());
        // Lose the response, replace connection and adapter, then replay overlap in a new media session.
        $this->voice->state = new MediaState($this->voice->ref('realtime:reconnected'),2,1);
        $this->voice->set(['eventId'=>'transport:new','idempotencyKey'=>'transport:new:key']);
        $fresh = new IsolatedDatabase();
        $this->pg = new PgFixture($fresh->pdo,$this->schema,$this->voice->tenant);
        $this->intake = new IntakeRepository($this->pg->environment);
        $this->voice->boundary = new Boundary($this->voice,$this->voice->hasher);
        self::assertSame($first->toArray(),$this->accept()->toArray());
        $this->counts(1);
    }

    public function testFinalHashAndRevisionConflict(): void
    {
        $first = $this->accept();
        foreach ([['revision'=>3], ['revision'=>1], ['textHash'=>new PayloadHash('sha-256:'.str_repeat('d',64))]] as $change) {
            $this->voice->set($change);
            try { $this->accept(); self::fail('Accepted final silently revised'); }
            catch (IntegrityConflict $error) { self::assertNotEmpty($error->getMessage()); }
        }
        $this->voice->set();
        self::assertSame($first->toArray(),$this->accept()->toArray());
        $this->counts(1);
    }

    public function testInterimAndStaleDoNotEnqueue(): void
    {
        foreach ([['final'=>false], ['mediaEpoch'=>2], ['contextRevision'=>2]] as $change) {
            $this->voice->set($change);
            try { $this->accept(); self::fail('Rejected STT reached intake'); }
            catch (Rejection $error) { self::assertContains($error->reason,
                ['INTERIM_TRANSCRIPT','STALE_MEDIA_EPOCH','STALE_CONTEXT_REVISION']); }
            $this->counts(0);
        }
    }

    public function testTextFallbackAndBargeIn(): void
    {
        $old = $this->voice->boundary->open('host:input'); $first = $this->accept();
        // Ingress-only interruption fence: the run remains accepted; no Action cancellation is inferred.
        $this->voice->state = new MediaState($this->voice->ref('realtime:2'),2,1);
        $this->voice->voiceAllowed = false;
        $this->voice->set(['channel'=>'text-fallback']);
        try { $this->voice->boundary->assertCurrent($old); self::fail('Old media envelope remained usable'); }
        catch (\AgenToolsPlatform\Core\Common\ContractViolation $error) {
            self::assertSame('stale_context',$error->errorCode->value);
        }
        self::assertSame($first->toArray(),$this->accept()->toArray());
        self::assertSame('accepted',$this->database->pdo->query('SELECT status FROM "'.$this->schema.'".runs')->fetchColumn());
        $this->counts(1);
    }

    public function testTenantAndSessionIsolation(): void
    {
        $first = $this->accept();
        $this->voice = new Fixture('tenant-b');
        $this->pg = new PgFixture($this->database->pdo,$this->schema,$this->voice->tenant);
        $context = $this->voice->boundary->open('host:input')->input->context;
        (new SessionRepository($this->pg->environment))->bind($context,$this->pg->binding($context));
        $this->intake = new IntakeRepository($this->pg->environment);
        self::assertNotSame($first->turnId->value,$this->accept()->turnId->value);
        $this->voice->set([], 'session:2');
        $context = $this->voice->boundary->open('host:input')->input->context;
        (new SessionRepository($this->pg->environment))->bind($context,$this->pg->binding($context));
        self::assertNotSame($first->turnId->value,$this->accept()->turnId->value);
        $this->counts(3);
    }
}
