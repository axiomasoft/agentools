<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Tests\Delivery;

require_once dirname(__DIR__,4).'/packages/agentools-core/tests/Actions/Confirmation/Fixture.php';
require_once __DIR__.'/ApprovalFixture.php';

use AgenToolsPlatform\Core\Common\{ContractViolation, PayloadHash, Ref, SessionId, UtcInstant};
use AgenToolsPlatform\Loqum\Delivery\{Coordinator, Ledger, Schema, VoiceConfirmation};
use AgenToolsPlatform\Examples\ConfirmedWrite\KeyMaterial;
use AgenToolsPlatform\Postgres\Confirmation\{ScopedReplayStore, Service};
use AgenToolsPlatform\Tests\Confirmation\Fixture as Actions;
use PHPUnit\Framework\TestCase;

final class ConfirmationTest extends TestCase
{
    private Actions $f;
    private ApprovalFixture $host;
    private Service $service;
    protected function setUp(): void
    {
        $this->f=new Actions();$c=$this->f->composition;
        $this->host=new ApprovalFixture($this->f);$keys=new KeyMaterial(str_repeat('s',32),str_repeat('e',32));
        $this->service=new Service($c->environment,$c->actions,$c->registry,$c->policy,new VoiceConfirmation($this->host,$c->hasher),
            $keys,new ScopedReplayStore($this->f->replayPdo,$this->f->schema),$keys,$c->host);
    }
    protected function tearDown(): void { if (isset($this->f)) { $this->f->close(); } }
    private function denies(callable $fn): void
    {
        try { $fn(); } catch (\Throwable) { self::assertSame(0,$this->f->count('example_owner_writes'));return; }self::fail('Unbound approval accepted');
    }
    public function testExactVoiceApproval(): void
    {
        $c=$this->f->composition;$s=$c->submission();$p=$this->service->prepare($s);$this->host->bind($p);
        $this->service->approve($p->token,$s);$this->service->approve($p->token,$s);
        self::assertSame(1,$this->f->count('confirmation_proofs'));
        $proof=$c->environment->db->row('SELECT channel,binding_hash FROM '.$c->environment->db->table('confirmation_proofs'));
        self::assertSame('voice',$proof['channel']);self::assertSame($p->binding->hash->value,$proof['binding_hash']);
        $this->host->bind($p,['presentationReceipt'=>new Ref($c->host->tenant,'changed:receipt')]);
        $this->denies(fn()=>$this->service->approve($p->token,$s));$this->host->bind($p);
        $grant=$this->service->commit($p->token,$s);$this->f->owner->write($grant,$s);self::assertSame(1,$this->f->count('example_owner_writes'));
        try { $this->service->commit($p->token,$s);self::fail('Spent token replayed'); }
        catch (\AgenTools\Confirmation\ConfirmationFailure $error) { self::assertSame('agentools.precondition.confirmation_token_spent',$error->error->code); }
    }
    public function testUnboundApprovalDenials(): void
    {
        $c=$this->f->composition;$s=$c->submission();$p=$this->service->prepare($s);
        $this->denies(fn()=>$this->service->approve($p->token,$s)); // Transcript “yes” supplies no host event.
        $this->host->bind($p);
        $changed=$c->submission('{"recipient":"r2","document":"d1","amount":100}');
        $this->denies(fn()=>$this->service->approve($p->token,$changed));
        foreach ([['session'=>new SessionId($c->host->tenant,'other')],['actor'=>new Ref($c->host->tenant,'other')],
                  ['proposal'=>new Ref($c->host->tenant,'other')],['bindingHash'=>new PayloadHash('sha-256:'.str_repeat('a',64))],
                  ['previewHash'=>new PayloadHash('sha-256:'.str_repeat('a',64))],['inputHash'=>new PayloadHash('sha-256:'.str_repeat('a',64))],
                  ['policyRevision'=>'policy-2'],['mediaEpoch'=>2],['contextRevision'=>2],['expiresAt'=>new UtcInstant('2026-11-03T09:00:00Z')]] as $change) {
            $this->host->bind($p,$change);$this->denies(fn()=>$this->service->approve($p->token,$s));
        }
        $this->host->bind($p);$this->host->presented=false;$this->denies(fn()=>$this->service->approve($p->token,$s));
        $this->host->presented=true;$c->host->revision='revoked';$this->denies(fn()=>$this->service->approve($p->token,$s));
        self::assertSame(0,$this->f->count('confirmation_proofs'));
    }
    public function testInterruptionDoesNotCancelEffect(): void
    {
        $c=$this->f->composition;$s=$c->submission();$p=$this->service->prepare($s);$this->host->bind($p);
        $this->service->approve($p->token,$s);$grant=$this->service->commit($p->token,$s);$this->f->owner->write($grant,$s);
        $media=new Fixture($this->f->database->pdo,$this->f->schema);Schema::install($media->pg->environment->db);
        $segment=$media->segment();$media->delivery->register($segment);$media->nextOffset=3;$media->delivery->send($segment);
        $media->delivery->interrupt($media->response);
        self::assertSame(1,$this->f->count('example_owner_writes'));
        self::assertSame('completed',$c->actions->status($grant->action,$c->context())->receipt->wireStatus);
        self::assertSame(3,$media->delivery->history($media->response)[0]['through_bytes']);
    }

    public function testApprovalRevokeBeforeOwnerCommit(): void
    {
        $c=$this->f->composition;$s=$c->submission();$p=$this->service->prepare($s);$this->host->bind($p);
        $this->service->approve($p->token,$s);$grant=$this->service->commit($p->token,$s);
        $c->environment->db->execute('UPDATE '.$c->environment->db->table('example_owner_permissions').' SET allowed=false');
        $this->denies(fn()=>$this->f->owner->write($grant,$s));
        self::assertSame('failed',$c->actions->status($grant->action,$c->context())->receipt->wireStatus);
    }
}
