<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Effects;

use AgenTools\Effects\EffectReconciliationRequired;
use AgenToolsPlatform\Core\Actions\Effects\{LookupResult, LookupStatus, ManualAuthority, ManualEvidence, OwnerPort, Request, Strategy};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict};
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\ActionId;

/** Explicit one-step owner operations. No parallel effect loop or unbounded automatic retry. */
final readonly class Service
{
    public function __construct(private Ledger $ledger, private OwnerPort $owner) {}

    /** Bind an audited operator command to this exact immutable effect and current fence. */
    public function assertTarget(ActionId $action, int $epoch): void
    {
        $request=$this->ledger->request();
        $action->tenant->assertSame($request->action->tenant);
        if ($action->value!==$request->action->value || $epoch!==$this->ledger->epoch) { throw new IntegrityConflict('Operational effect target/fence conflict'); }
    }

    public function execute(): LookupResult
    {
        if ($this->owner->strategy() === Strategy::Unsupported) {
            // High-risk unsupported owner is denied before IO. Manual workflow may
            // still reconcile pre-existing unknown requests from an older adapter.
            throw new ContractViolation(ErrorCode::ManualResolutionRequired);
        }
        $request = $this->ledger->prepare($this->owner->namespace(),$this->owner->strategy());
        if ($request->strategy === Strategy::ReadBeforeWrite) {
            try { $lookup = $this->owner->lookup($request); }
            catch (\Throwable) { $this->unresolved('lookup_unavailable'); }
            $lookup->assertRequest($request);
            if (in_array($lookup->status,[LookupStatus::Succeeded,LookupStatus::Failed],true)) { return $this->save($lookup); }
            if ($lookup->status !== LookupStatus::Absent) { $this->unresolved('owner_outcome_unknown'); }
        }
        return $this->send($request);
    }

    public function reconcile(bool $retry = false): LookupResult
    {
        $request = $this->ledger->request();
        $this->binding($request);
        $accepted = $this->ledger->accepted();
        if ($accepted !== null) { return $accepted; }
        try { $result = $this->owner->lookup($request); }
        catch (ContractViolation|IntegrityConflict $error) { throw $error; }
        catch (\Throwable) { $this->unresolved('lookup_unavailable'); }
        $result->assertRequest($request);
        if (in_array($result->status,[LookupStatus::Succeeded,LookupStatus::Failed],true)) {
            return $this->save($result);
        }
        if ($retry) { return $this->send($this->ledger->reserveRetry($result)); }
        $this->unresolved($result->status === LookupStatus::Unsupported ? 'manual_resolution_required' : 'owner_outcome_unknown');
    }

    /** @param (\Closure():void)|null $audit */
    public function resolve(ManualEvidence $evidence, ManualAuthority $authority, int $expectedVersion,
        ?Ref $operation = null, string $reason = 'owner_evidence', ?\Closure $audit = null): LookupResult
    {
        $request = $this->ledger->request();
        $evidence->outcome->assertRequest($request);
        $authority->authorize($request,$evidence);
        $this->ledger->accept($evidence->outcome,$evidence,$expectedVersion,$operation,$reason,$audit);
        return $evidence->outcome;
    }

    private function send(Request $request): LookupResult
    {
        $this->binding($request);
        try { $result = $this->owner->execute($request); }
        catch (\Throwable) { $this->unresolved('owner_send_unknown'); }
        $result->assertRequest($request);
        if (!in_array($result->status,[LookupStatus::Succeeded,LookupStatus::Failed],true)) { $this->unresolved('owner_send_unknown'); }
        return $this->save($result);
    }

    private function binding(Request $request): void
    {
        if ($this->owner->namespace() !== $request->ownerNamespace || $this->owner->strategy() !== $request->strategy) {
            throw new IntegrityConflict('Owner capability or namespace changed');
        }
    }

    private function save(LookupResult $result): LookupResult
    {
        try { $this->ledger->accept($result); }
        catch (ContractViolation|IntegrityConflict $error) { throw $error; }
        catch (\Throwable) { $this->unresolved('local_receipt_lost'); }
        return $result;
    }

    private function unresolved(string $reason): never
    {
        try { $this->ledger->unknown($reason); } catch (\Throwable) {
            // Durable pending + applying still means unknown if the entire local journal is unavailable.
        }
        throw new EffectReconciliationRequired('Lookup the saved owner operation key or use authenticated evidence-based resolution.');
    }
}
