<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Security\{BoundaryClock, DisclosureMode};
use AgenToolsPlatform\Postgres\Runtime\Connection as C;

/** One delivery bridge over existing runtime output; no model, Actions or cancellation dependency. */
final readonly class Coordinator
{
    public function __construct(private Ledger $ledger,private Owner $owner,private CanonicalHasher $hasher,private BoundaryClock $clock) {}

    public function register(Segment $s): void
    {
        $epoch=$this->guard($s->response,'register');
        if ($s->response->policy->mode===DisclosureMode::Buffered && $s->sequence!==1) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        $this->ledger->register($s,$epoch);
    }

    /** @return array<string,mixed> */
    public function send(Segment $s): array
    {
        $epoch=$this->guard($s->response,'speech');$this->checked($s,'speech');
        $a=$this->ledger->claim($s,$epoch);
        if ($a===null) { return $this->ledger->state($s); }
        try {
            $this->guard($s->response,'synthesize');
            $row=$this->ledger->state($s);
            $artifact=$row['artifact_ref']===null?$this->owner->synthesize($a):new \AgenToolsPlatform\Core\Common\Ref($s->key->tenant,C::text($row,'artifact_ref'));
            $this->ledger->synthesized($a,$artifact);
            $this->guard($s->response,'play');$this->checked($s,'speech');$this->ledger->ready($a);
        } catch (\Throwable $error) {
            $this->ledger->uncertain($a,true);throw $error;
        }
        try { $receipt=$this->owner->play($a,$artifact); }
        catch (ContractViolation|\AgenToolsPlatform\Core\Common\IntegrityConflict $error) { $this->ledger->uncertain($a);throw $error; }
        catch (\Throwable) { $this->ledger->uncertain($a);return $this->ledger->state($s); }
        if ($receipt===null) { $this->ledger->uncertain($a); }
        else {
            try { $this->ledger->record($a,$receipt); }
            catch (\Throwable $error) { $this->ledger->uncertain($a);throw $error; }
        }
        return $this->ledger->state($s);
    }

    public function reconcile(Segment $s): void
    {
        $epoch=$this->guard($s->response,'receipt');$row=$this->ledger->state($s);
        if (!in_array(C::text($row,'state'),['sending','unknown'],true)) { return; }
        $a=new Attempt($s,C::number($row,'attempt_offset'),$epoch,$this->hasher);
        $found=$this->owner->status($a);
        if ($found->status==='found' && $found->receipt!==null) { $this->ledger->record($a,$found->receipt); }
        elseif ($found->status==='not_found') { $this->ledger->notFound($a); }
    }

    public function receive(Segment $s,string $handle): void
    {
        $epoch=$this->guard($s->response,'receipt');$receipt=$this->owner->receipt($s,$handle);
        $row=$this->ledger->state($s);
        $this->ledger->record(new Attempt($s,C::number($row,'attempt_offset'),$epoch,$this->hasher),$receipt);
    }

    public function display(Segment $s): void
    {
        $epoch=$this->guard($s->response,'display');$this->checked($s,'display');
        $this->ledger->assertActive($s,$epoch);
        try { $receipt=$this->owner->display($s); }
        catch (ContractViolation|\AgenToolsPlatform\Core\Common\IntegrityConflict $error) { $this->ledger->displayUnknown($s,$epoch);throw $error; }
        catch (\Throwable) { $this->ledger->displayUnknown($s,$epoch);return; }
        if ($receipt===null) { $this->ledger->displayUnknown($s,$epoch);return; }
        $s->key->assertSame($receipt->segment);
        if ($receipt->mediaEpoch!==$epoch) { throw new ContractViolation(ErrorCode::StaleContext); }
        $this->ledger->display($s,$epoch,$receipt->ref,$receipt->offset);
    }

    public function reconnect(Response $r): void { $this->ledger->reconnect($r,$this->guard($r,'reconnect')); }

    public function interrupt(Response $r): void
    {
        $epoch=$this->stopEpoch($r);
        $this->ledger->interrupt($r,$epoch);
        $this->owner->stop($r,$epoch);
    }

    public function revoke(Response $r): void
    {
        $epoch=$this->stopEpoch($r);$this->ledger->interrupt($r,$epoch,true);
        try { $this->owner->stop($r,$epoch); }
        finally { $this->ledger->retained($r,$this->owner->revoke($r)); }
    }

    public function queue(Segment $s,UiCommand $command): void
    {
        $epoch=$this->guard($s->response,'command');$this->commandCurrent($s,$command);
        $this->ledger->queue($s,$epoch,$command);
    }

    public function command(Segment $s,UiCommand $command): void
    {
        $epoch=$this->guard($s->response,'command');$this->commandCurrent($s,$command);
        if (!$this->ledger->claimCommand($s,$epoch,$command)) { return; }
        // A lost receipt remains executing/unknown and cannot be claimed again without owner reconciliation.
        $receipt=$this->owner->command($command);$this->ledger->commandReceipt($command,$receipt);
    }

    /** @return list<array<string,mixed>> */
    public function history(Response $r): array { $this->guard($r,'history');return $this->ledger->history($r); }

    private function guard(Response $r,string $operation): int
    {
        $r->context->assertCurrent($this->owner);$this->owner->assertCurrent($r,$operation);
        $current=$this->owner->policy($r);$r->response->tenant->assertSame($current->ref->tenant);
        if ($current->toArray()!==$r->policy->toArray()) { throw new ContractViolation(ErrorCode::StaleContext); }
        $media=$this->owner->media($r);$r->response->tenant->assertSame($media->realtimeSession->tenant);
        if ($media->contextRevision!==$r->contextRevision) { throw new ContractViolation(ErrorCode::StaleContext); }
        return $media->epoch;
    }

    private function stopEpoch(Response $r): int
    {
        $this->owner->authorizeStop($r);$this->owner->assertCurrent($r,'stop');
        $media=$this->owner->media($r);$r->response->tenant->assertSame($media->realtimeSession->tenant);return $media->epoch;
    }

    private function checked(Segment $s,string $channel): void
    {
        $p=$this->owner->payload($s,$channel);$r=$s->response;
        $r->response->assertSame($p->response);
        $expected=$channel==='speech'?$s->speakableHash:$s->displayHash;
        $expected->assertSame($p->hash);$expected->assertSame($this->hasher->hash($s->key->tenant,'disclosure-payload',$p->bytes));
        if ($p->responseVersion!==$r->version || $p->ordinal!==$s->sequence
            || strlen($p->bytes)!==($channel==='speech'?$s->speakableBytes:$s->displayBytes)
            || (!$p->prechecked && ($r->policy->mode!==DisclosureMode::Live
                || $p->liveApproval?->value!==$r->policy->liveApproval?->value))) { throw new ContractViolation(ErrorCode::AccessDenied); }
        if ($channel==='speech') {
            foreach ($s->boundaries as $offset) {
                if (preg_match('//u',substr($p->bytes,0,$offset))!==1) { throw new ContractViolation(ErrorCode::InvalidInput); }
            }
        }
    }

    private function commandCurrent(Segment $s,UiCommand $command): void
    {
        $s->key->assertSame($command->playbackAnchor);
        if ($command->contextRevision!==$s->response->contextRevision
            || new \DateTimeImmutable($command->expiresAt->value)<=new \DateTimeImmutable($this->clock->now()->value)) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }
    }
}
