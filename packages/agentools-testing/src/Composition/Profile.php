<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

/** Explicit test-only cleanup, including failures. Owner facts/scripts are intentionally separate. */
final readonly class Profile
{
    public function __construct(public Scope $scope,public FakeAuthority $authority,public FakeTransport $transport,
        public FakeBudget $budget,public FakePayloads $payloads,public FakeSecrets $secrets,
        public FakeHistory $history,public FakeOwner $owner,public FakeDelivery $delivery) {}
    public function reset(): void
    {
        $failed=false;
        foreach ([$this->transport->reset(...),$this->budget->reset(...),$this->payloads->reset(...),$this->secrets->reset(...),
            $this->history->reset(...),$this->owner->reset(...),$this->delivery->reset(...),$this->scope->reset(...),$this->authority->reset(...)] as $reset) {
            try{$reset();}catch(\Throwable){$failed=true;}
        }
        if($failed){throw new \RuntimeException('Testing profile reset failed');}
    }
}
