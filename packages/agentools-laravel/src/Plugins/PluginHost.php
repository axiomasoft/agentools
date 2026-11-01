<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Plugins;

use AgenToolsPlatform\Core\Actions\ActionView;
use AgenToolsPlatform\Core\Actions\Confirmation\Preview;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Runtime\InvocationCheckpoint;
use AgenToolsPlatform\Core\Security\TrustedInput;
use AgenToolsPlatform\Laravel\Definitions\{Availability, Reference, Registry as Definitions};
use AgenToolsPlatform\Laravel\Managed\{DeliveryReceipt, Entry, HostAdmission, HostBindings, Input, LifecycleFailure, ProductionBinding, Work};

/** Opt-in HostBindings.host decorator. Mandatory host methods stay outside optional failure handling. */
final readonly class PluginHost implements HostAdmission,ProductionBinding
{
    public function __construct(private HostAdmission $host,private Definitions $definitions,private Reference $definition,
        private Availability $availability,private Registry $plugins,private DataSink $sink) {}
    public function authenticate(Input $input): Ref { return $this->host->authenticate($input); }
    public function trusted(Input $input): TrustedInput { return $this->host->trusted($input); }
    public function profileData(TrustedInput $input): BundleRef
    {
        $this->definitions->current($this->definition,$input->context,$this->availability);
        return $this->host->profileData($input);
    }
    public function compile(TrustedInput $input,BundleRef $bundle,Entry $entry): Work
    {
        $definition=$this->definitions->current($this->definition,$input->context,$this->availability);
        $result=$this->plugins->run($definition->plugins,new HookInput(Phase::ContextData,$input->context->session,
            $input->context->purpose,$input->payload->inputRef,$entry),$input,$definition->restrictions->pluginTokenBudget);
        $this->sink->accept($input,$result->contribution);
        foreach ($result->decisions as $decision) {
            $input->context->identity->tenant->assertSame($this->host->decision($input,
                'plugin.context:'.substr(hash('sha256',$decision['plugin']),0,24),$decision['code'])->tenant);
        }
        $work=$this->host->compile($input,$bundle,$entry);
        if ($work->plan!==null) {
            if (!in_array($work->plan->deployment->region,$definition->restrictions->regions,true)) {
                throw new \AgenToolsPlatform\Core\Common\ContractViolation(\AgenToolsPlatform\Core\Common\ErrorCode::PolicyConflict);
            }
            foreach ($work->plan->tools as $tool) {
                if (!in_array($tool->id,$definition->restrictions->toolIds,true)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(\AgenToolsPlatform\Core\Common\ErrorCode::AccessDenied); }
            }
        } elseif ($work->action!==null && !in_array($work->action->request->definition->id,$definition->restrictions->toolIds,true)) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(\AgenToolsPlatform\Core\Common\ErrorCode::AccessDenied);
        }
        return $work;
    }
    public function deployment(TrustedInput $input,Work $work): void { $this->host->deployment($input,$work); }
    public function disclose(TrustedInput $input,InvocationCheckpoint|ActionView|Preview $result): Ref
    {
        $checked=$this->host->disclose($input,$result);
        $definition=$this->definitions->current($this->definition,$input->context,$this->availability);
        // Entry is metadata for observers. Security semantics never depend on an entry flag.
        $hooks=$this->plugins->run($definition->plugins,new HookInput(Phase::CheckedDisclosure,$input->context->session,
            $input->context->purpose,$input->payload->inputRef,null,$checked),$input,0);
        foreach ($hooks->decisions as $decision) {
            $input->context->identity->tenant->assertSame($this->host->decision($input,
                'plugin.disclosure:'.substr(hash('sha256',$decision['plugin']),0,24),$decision['code'])->tenant);
        }
        return $checked;
    }
    public function deliver(TrustedInput $input,Ref $checked,Entry $entry): DeliveryReceipt { return $this->host->deliver($input,$checked,$entry); }
    public function decision(TrustedInput $input,string $stage,string $code): Ref { return $this->host->decision($input,$stage,$code); }
    public function reset(): void
    {
        $failed=false;foreach ([$this->host->reset(...),$this->plugins->reset(...),$this->availability->reset(...),$this->sink->reset(...)] as $reset) {
            try { $reset(); } catch (\Throwable) { $failed=true; }
        }
        if ($failed) { throw new LifecycleFailure(); }
    }
    public function assertProduction(): void
    {
        foreach ([$this->host,$this->availability,$this->plugins,$this->sink] as $service) { HostBindings::assertProductionService($service); }
    }
}
