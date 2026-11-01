<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Plugins;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict};
use AgenToolsPlatform\Core\Security\TrustedInput;
use AgenToolsPlatform\Laravel\Definitions\{Availability, Reference};
use AgenToolsPlatform\Laravel\Managed\{HostBindings, LifecycleFailure, ProductionBinding};

/** Installed code registry. DB settings select pinned IDs, never executable types or guards. */
final readonly class Registry implements ProductionBinding
{
    /** @var array<string,Registration> */
    private array $entries;
    /** @param list<Registration> $registrations */
    public function __construct(array $registrations,private Availability $availability)
    {
        $entries=[];foreach ($registrations as $registration) {
            $key=$registration->manifest->ref->key();
            if (isset($entries[$key])) { throw new IntegrityConflict('Duplicate code plugin'); }
            $entries[$key]=$registration;
        }
        ksort($entries,SORT_STRING);$this->entries=$entries;
    }
    /** @param list<Reference> $selected */
    public function run(array $selected,HookInput $input,TrustedInput $trusted,int $budget): HookResult
    {
        $input->session->assertSame($trusted->context->session);
        $trusted->payload->inputRef->assertSame($input->inputRef);
        if ($input->purpose!==$trusted->context->purpose || $budget<0 || $budget>1000000) { throw new ContractViolation(ErrorCode::InvalidInput); }
        self::assertSelection($selected);
        $fragments=[];$decisions=[];$seen=[];$tokens=0;
        foreach ($selected as $ref) {
            if (isset($seen[$ref->key()])) { throw new IntegrityConflict('Duplicate selected plugin'); }$seen[$ref->key()]=true;
            $ref->owner->assertVisible($input->session->tenant);
            $registration=$this->entries[$ref->key()]??throw new ContractViolation(ErrorCode::CapabilityUnavailable);
            $manifest=$registration->manifest;$ref->version->hash->assertSame($manifest->ref->version->hash);
            if (!in_array($input->phase,$manifest->phases,true)) { continue; }
            $this->availability->assertCurrent($trusted->context,$ref);
            if (!in_array($input->purpose,$manifest->purposes,true)) { throw new ContractViolation(ErrorCode::AccessDenied); }
            try { $result=$registration->plugin->apply($input); }
            catch (ContractViolation $error) { throw new ContractViolation($error->errorCode); }
            catch (LifecycleFailure) { throw new LifecycleFailure(); }
            catch (IntegrityConflict) { throw new IntegrityConflict('Plugin integrity failure'); }
            catch (\Throwable) {
                if ($manifest->failure===FailurePolicy::Abort) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
                $decisions[]=['plugin'=>$ref->key(),'phase'=>$input->phase->value,'status'=>'omitted','code'=>'optional_plugin_unavailable'];continue;
            }
            if ($input->phase!==Phase::ContextData && $result->fragments!==[]) { throw new ContractViolation(ErrorCode::PolicyConflict); }
            $pluginTokens=0;
            foreach ($result->fragments as $fragment) {
                $input->session->tenant->assertSame($fragment->ref->tenant);
                $pluginTokens+=$fragment->tokens;$tokens+=$fragment->tokens;
                if ($pluginTokens>$manifest->tokenBudget || $tokens>$budget) { throw new ContractViolation(ErrorCode::AdmissionDenied); }
                $fragments[]=$fragment;
            }
            $decisions[]=['plugin'=>$ref->key(),'phase'=>$input->phase->value,'status'=>'applied','code'=>'untrusted_data_only'];
        }
        return new HookResult(new Contribution($fragments),$decisions);
    }
    private static function assertSelection(mixed $selected): void
    {
        if (!is_array($selected) || !array_is_list($selected)) { throw new ContractViolation(ErrorCode::InvalidInput); }
        foreach ($selected as $ref) { if (!$ref instanceof Reference) { throw new ContractViolation(ErrorCode::InvalidInput); } }
    }
    public function reset(): void
    {
        $failed=false;foreach ($this->entries as $entry) { try { $entry->plugin->reset(); } catch (\Throwable) { $failed=true; } }
        try { $this->availability->reset(); } catch (\Throwable) { $failed=true; }
        if ($failed) { throw new LifecycleFailure(); }
    }
    public function assertProduction(): void
    {
        HostBindings::assertProductionService($this->availability);
        foreach ($this->entries as $entry) { HostBindings::assertProductionService($entry->plugin); }
    }
}
