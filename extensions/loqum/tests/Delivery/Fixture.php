<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Tests\Delivery;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, InstanceId, Ref, SessionId, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Security\{BoundaryClock, DisclosureMode, DisclosurePayload, DisclosurePolicy, HostIdentity};
use AgenToolsPlatform\Loqum\Delivery\{Attempt, Coordinator, DisplayReceipt, Ledger, Lookup, Owner, PlayoutReceipt, Response, Schema, Segment, UiCommand};
use AgenToolsPlatform\Loqum\Ingress\MediaState;
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Runtime\{Connection, SessionRepository};
use AgenToolsPlatform\Postgres\Tests\Runtime\Fixture as PgFixture;

final class Fixture implements Owner,BoundaryClock
{
    public PgFixture $pg;
    public Response $response;
    public Ledger $ledger;
    public Coordinator $delivery;
    public int $epoch=1;
    public int $revision=1;
    public DisclosurePolicy $outputPolicy;
    public bool $voiceAllowed=true;
    public bool $stopAllowed=true;
    public bool $historyAllowed=true;
    public bool $checked=true;
    public bool $ttsOutage=false;
    public bool $lostAck=false;
    public bool $unknown=false;
    public bool $notFound=false;
    public bool $displayUnknown=false;
    public int $stops=0;
    public int $retentions=0;
    public int $commands=0;
    public int $displays=0;
    public ?int $nextOffset=null;
    public string $evidence='client_ack';
    public array $syntheses=[];
    public array $plays=[];
    public array $receipts=[];
    public array $segments=[];
    public ?PlayoutReceipt $incoming=null;
    public ?\Closure $afterSynthesis=null;
    public ?\Closure $beforePlay=null;

    public function __construct(\PDO $pdo,string $schema,string $tenant='tenant-a')
    {
        $this->pg=new PgFixture($pdo,$schema,new TenantId($tenant));
        $c=$this->pg->context();
        (new SessionRepository($this->pg->environment))->bind($c,$this->pg->binding($c));
        $receipt=(new IntakeRepository($this->pg->environment))->accept($this->pg->input($c),$this->pg->snapshot($c));
        $this->outputPolicy=new DisclosurePolicy($this->ref('speech-policy'),'v1',DisclosureMode::CheckedSegments,false,65536);
        $this->response=new Response($c,new InstanceId($this->pg->tenant,'instance'),$receipt->turnId,$receipt->runId,
            $this->ref('response:1'),1,$this->ref('chatom:conversation'),1,$this->outputPolicy,$this->pg->hasher);
        $this->ledger=new Ledger($this->pg->environment->db,$this->pg->hasher);
        $this->delivery=new Coordinator($this->ledger,$this,$this->pg->hasher,$this);
    }
    public function ref(string $id): Ref { return new Ref($this->pg->tenant,$id); }
    public function now(): UtcInstant { return new UtcInstant('2026-11-03T10:00:00Z'); }
    public function segment(string $id='segment:1',int $sequence=1,string $speech='one two',string $display='one two shown'): Segment
    {
        $s=new Segment($this->response,$id,$sequence,$this->ref('display:'.$id),$this->pg->hasher->hash($this->pg->tenant,'disclosure-payload',$display),strlen($display),
            $this->ref('speakable:'.$id),$this->pg->hasher->hash($this->pg->tenant,'disclosure-payload',$speech),strlen($speech),[0,3,strlen($speech)],$this->pg->hasher);
        $this->segments[$s->key->value]=['speech'=>$speech,'display'=>$display];return $s;
    }
    public function authorize(SessionId $session,string $purpose): HostIdentity { return $this->pg->host->authorize($session,$purpose); }
    public function authorizeStop(Response $response): void
    {
        $this->pg->environment->db->assertIdle();$this->pg->tenant->assertSame($response->context->session->tenant);
        if (!$this->stopAllowed) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
    public function assertCurrent(Response $response,string $operation): void
    {
        $this->pg->environment->db->assertIdle();
        if (($operation!=='stop' && !$this->voiceAllowed && in_array($operation,['speech','synthesize','play'],true))
            || ($operation==='history' && !$this->historyAllowed)) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
    public function media(Response $response): MediaState { return new MediaState($this->ref('realtime:'.$this->epoch),$this->epoch,$this->revision); }
    public function policy(Response $response): DisclosurePolicy { return $this->outputPolicy; }
    public function payload(Segment $segment,string $channel): DisclosurePayload
    {
        if (!$this->checked) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $bytes=$this->segments[$segment->key->value][$channel];
        return new DisclosurePayload($this->ref('checked:'.$segment->id.':'.$channel),$segment->response->response,$segment->response->version,
            $segment->sequence,$bytes,$this->pg->hasher->hash($this->pg->tenant,'disclosure-payload',$bytes),true,$this->ref('inspection'),null);
    }
    public function synthesize(Attempt $attempt): Ref
    {
        $this->pg->environment->db->assertIdle();$this->syntheses[]=[$attempt->key->value,$attempt->offset];
        if ($this->ttsOutage) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
        if ($this->afterSynthesis!==null) { ($this->afterSynthesis)(); }
        return $this->ref('audio:'.$attempt->key->value);
    }
    public function play(Attempt $attempt,Ref $artifact): ?PlayoutReceipt
    {
        if ($this->beforePlay!==null) { ($this->beforePlay)(); }
        $attempt->segment->response->context->assertCurrent($this);$this->assertCurrent($attempt->segment->response,'play');
        $this->pg->environment->db->assertIdle();$this->plays[]=[$attempt->key->value,$attempt->offset];
        if ($this->unknown) { return null; }
        $offset=$this->nextOffset??$attempt->segment->speakableBytes;
        $receipt=new PlayoutReceipt($this->ref('receipt:'.$attempt->key->value.':'.$this->evidence),$attempt->segment->response->response,1,
            $attempt->segment->key,$attempt->key,$attempt->epoch,$offset,$offset===$attempt->segment->speakableBytes?'completed':'failed',$this->evidence);
        $this->receipts[$attempt->key->value]=$receipt;
        if ($this->lostAck) { throw new \RuntimeException('Private transport payload must not escape logs'); }
        return $receipt;
    }
    public function status(Attempt $attempt): Lookup
    {
        $this->pg->environment->db->assertIdle();
        if ($this->notFound) { return new Lookup('not_found'); }
        return isset($this->receipts[$attempt->key->value])?new Lookup('found',$this->receipts[$attempt->key->value]):new Lookup('unknown');
    }
    public function receipt(Segment $segment,string $handle): PlayoutReceipt
    {
        if ($handle!=='host:receipt' || $this->incoming===null) { throw new ContractViolation(ErrorCode::AccessDenied); }
        return $this->incoming;
    }
    public function display(Segment $segment): ?DisplayReceipt
    {
        $segment->response->context->assertCurrent($this);$this->assertCurrent($segment->response,'display');
        $this->pg->environment->db->assertIdle();++$this->displays;
        return $this->displayUnknown?null:new DisplayReceipt($this->ref('display-receipt:'.$segment->id),$segment->key,$this->epoch,$segment->displayBytes);
    }
    public function command(UiCommand $command): Ref { $this->pg->environment->db->assertIdle();++$this->commands;return $this->ref('ui-receipt:'.$command->id->value); }
    public function stop(Response $response,int $epoch): void { $this->pg->environment->db->assertIdle();++$this->stops; }
    public function revoke(Response $response): Ref { $this->pg->environment->db->assertIdle();++$this->retentions;return $this->ref('owner:retention-receipt'); }
}
