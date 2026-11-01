<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Owners;

use AgenToolsPlatform\Core\Actions\Effects\{LookupResult, LookupStatus, OwnerPort, Request, Strategy};
use AgenToolsPlatform\Core\Common\Ref;

/** Explicit contract-only adapter. It never qualifies durability or a production owner. */
final class ScriptedOwner implements OwnerPort
{
    public int $calls = 0;
    public int $lookups = 0;
    /** @var array<string,LookupResult> */
    private array $receipts = [];

    public function __construct(private Strategy $capability = Strategy::NativeKey,
        public LookupStatus $missing = LookupStatus::Unknown) {}

    public function namespace(): string { return 'test.scripted-owner'; }
    public function strategy(): Strategy { return $this->capability; }

    public function execute(Request $request): LookupResult
    {
        ++$this->calls;
        $key = $request->action->tenant->value.'|'.$request->ownerNamespace.'|'.$request->operationKey;
        $result = $this->receipts[$key] ?? new LookupResult(LookupStatus::Succeeded,
            new Ref($request->action->tenant,$request->operationKey),$request->ownerNamespace,$request->requestHash,
            new Ref($request->action->tenant,'test:receipt:'.$request->operationKey));
        $result->assertRequest($request);
        return $this->receipts[$key] = $result;
    }

    public function lookup(Request $request): LookupResult
    {
        ++$this->lookups;
        $key = $request->action->tenant->value.'|'.$request->ownerNamespace.'|'.$request->operationKey;
        $result = $this->receipts[$key] ?? new LookupResult($this->capability === Strategy::Unsupported ? LookupStatus::Unsupported : $this->missing,
            new Ref($request->action->tenant,$request->operationKey),$request->ownerNamespace,$request->requestHash,
            $this->missing === LookupStatus::Absent ? new Ref($request->action->tenant,'test:absence-proof') : null);
        $result->assertRequest($request);
        return $result;
    }
}
