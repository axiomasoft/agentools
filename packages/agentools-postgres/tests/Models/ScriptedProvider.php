<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Models;

use AgenToolsPlatform\Core\Models\{Deployment, ModelRequest, ModelResponse, ModelTransport};
use AgenToolsPlatform\Core\Runtime\{InvocationPlan, ProviderLookup, ProviderOutcome};
use AgenToolsPlatform\Core\Security\ExecutionContext;

final class ScriptedProvider implements ModelTransport, ProviderLookup
{
    public bool $lost = false;
    public bool $tools = true;
    public ?\AgenToolsPlatform\Core\Models\ModelUsage $usage = null;
    public function __construct(private TestRig $rig) {}
    public function generate(ModelRequest $request, Deployment $deployment): ModelResponse
    {
        $this->rig->boundary('intent');
        $intents = $this->rig->sql('SELECT admission_ref,request_hash FROM invocation_intents WHERE tenant_id=:tenant AND id=:id', [
            'tenant' => $request->invocationId->tenant->value, 'id' => $request->invocationId->value])->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($intents) || !is_string($intents['admission_ref'] ?? null)
            || ($intents['request_hash'] ?? null) !== substr($this->rig->plan->requestHash->value, 8)) {
            throw new \LogicException('Provider called without committed admitted intent');
        }
        $this->rig->sql('INSERT INTO host_provider(tenant,id,request_hash,tools) VALUES (:tenant,:id,:hash,:tools)', [
            'tenant' => $request->invocationId->tenant->value, 'id' => $request->invocationId->value,
            'hash' => $this->rig->plan->requestHash->value, 'tools' => $this->tools ? 1 : 0]);
        $this->rig->boundary('provider');
        if ($this->lost) { throw new \RuntimeException('Scripted response loss'); }
        return $this->rig->response($this->tools);
    }
    public function stream(ModelRequest $request, Deployment $deployment): iterable { throw new \LogicException('No second loop'); }
    public function find(ExecutionContext $context, InvocationPlan $plan): ?ProviderOutcome
    {
        $this->rig->boundary('lookup');
        $row = $this->rig->sql('SELECT request_hash,tools FROM host_provider WHERE tenant=:tenant AND id=:id', [
            'tenant' => $context->session->tenant->value, 'id' => $plan->request->invocationId->value])->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) { return null; }
        if (($row['request_hash'] ?? null) !== $plan->requestHash->value) { throw new \LogicException('Unverified lookup response'); }
        return new ProviderOutcome($this->rig->response($row['tools'] === 1), $plan->requestHash,
            new \AgenToolsPlatform\Core\Common\Ref($plan->runId->tenant, 'verified-provider-lookup:' . $plan->request->invocationId->value));
    }
}
