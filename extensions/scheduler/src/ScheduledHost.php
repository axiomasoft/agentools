<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

use AgenToolsPlatform\Core\Actions\ActionView;
use AgenToolsPlatform\Core\Actions\Confirmation\Preview;
use AgenToolsPlatform\Core\Common\{IntegrityConflict, Ref};
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Runtime\InvocationCheckpoint;
use AgenToolsPlatform\Core\Security\TrustedInput;
use AgenToolsPlatform\Laravel\Managed\{DeliveryReceipt, Entry, HostAdmission, HostBindings, Input, LifecycleFailure, ProductionBinding, Work};

/** Code-bound host decorator: reuse authentication, compilation, guards, budget and delivery unchanged. */
final readonly class ScheduledHost implements HostAdmission, ProductionBinding
{
    public function __construct(private HostAdmission $host, private Store $store) {}
    public function assertStore(Store $store): void
    {
        if ($this->store !== $store) { throw new IntegrityConflict('Scheduler binding conflict'); }
    }
    public function assertProduction(): void
    {
        HostBindings::assertProductionService($this->host); $this->store->assertProduction();
    }
    public function authenticate(Input $input): Ref { return $this->host->authenticate($input); }
    public function trusted(Input $input): TrustedInput
    {
        $trusted = $this->host->trusted($input);
        if (!str_starts_with($input->ref,'occurrence:') && $trusted->source->kind !== 'scheduler.occurrence') { return $trusted; }
        if ($input->entry !== Entry::Background || $trusted->source->eventId !== $input->ref) {
            throw new IntegrityConflict('Occurrence requires its bound background ingress');
        }
        $saved = $this->store->resolve($input->ref,$trusted->context);
        // A host handle never grants permission to substitute the frozen source/payload.
        if ($trusted->source->toArray() !== $saved['input']->source->toArray()
            || $trusted->payload->toArray() !== $saved['input']->payload->toArray()) {
            throw new IntegrityConflict('Occurrence host binding conflict');
        }
        return $trusted;
    }
    public function profileData(TrustedInput $input): BundleRef { return $this->host->profileData($input); }
    public function compile(TrustedInput $input, BundleRef $bundle, Entry $entry): Work
    {
        $work = $this->host->compile($input,$bundle,$entry);
        if ($input->source->kind === 'scheduler.occurrence') {
            $saved = $this->store->resolve($input->source->eventId,$input->context);
            if ($work->plan === null || $work->action !== null) { throw new IntegrityConflict('Occurrence requires its bound Run'); }
            $saved['run']->assertSame($work->plan->runId); $saved['turn']->assertSame($work->plan->turnId);
        }
        return $work;
    }
    public function deployment(TrustedInput $input, Work $work): void { $this->current($input); $this->host->deployment($input,$work); }
    public function disclose(TrustedInput $input, InvocationCheckpoint|ActionView|Preview $result): Ref { $this->current($input); return $this->host->disclose($input,$result); }
    public function deliver(TrustedInput $input, Ref $checked, Entry $entry): DeliveryReceipt { $this->current($input); return $this->host->deliver($input,$checked,$entry); }
    public function decision(TrustedInput $input, string $stage, string $code): Ref { return $this->host->decision($input,$stage,$code); }
    public function reset(): void
    {
        $failed = false;
        foreach ([$this->store->authority,$this->host] as $port) {
            try { $port->reset(); } catch (\Throwable) { $failed = true; }
        }
        if ($failed) { throw new LifecycleFailure(); }
    }
    private function current(TrustedInput $input): void
    {
        if ($input->source->kind === 'scheduler.occurrence') { $this->store->resolve($input->source->eventId,$input->context); }
    }
}
