<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Models;

use AgenToolsPlatform\Core\Actions\ActionView;
use AgenToolsPlatform\Core\Common\{ActionReceipt, IntegrityConflict};
use AgenToolsPlatform\Core\Runtime\{ProposalDispatcher, SavedProposal};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Scripted durable acceptance owner only; does not execute or claim qualification of effects. */
final class HostActions implements ProposalDispatcher
{
    public bool $completed = true;
    public function __construct(private TestRig $rig) {}
    public function submit(ExecutionContext $context, SavedProposal $proposal): ActionView
    {
        $this->rig->boundary('proposals');
        $context->assertCurrent($this->rig->fixture->host);
        $map = $this->rig->sql('SELECT action_ref FROM proposal_map WHERE tenant_id=:tenant AND id=:id', [
            'tenant' => $proposal->id->tenant->value, 'id' => $proposal->id->value])->fetchColumn();
        if ($map !== $proposal->actionId->value || $this->rig->count('usage_versions') < 1) {
            throw new \LogicException('Action submit before durable response/usage/mapping');
        }
        $this->rig->sql('INSERT INTO host_actions(tenant,id,hash) VALUES (:tenant,:id,:hash) ON CONFLICT DO NOTHING', [
            'tenant' => $proposal->id->tenant->value, 'id' => $proposal->actionId->value, 'hash' => $proposal->inputHash->value]);
        $this->rig->boundary('submit');
        return $this->status($context, $proposal);
    }
    public function status(ExecutionContext $context, SavedProposal $proposal): ActionView
    {
        $this->rig->boundary('action-status');
        $hash = $this->rig->sql('SELECT hash FROM host_actions WHERE tenant=:tenant AND id=:id', [
            'tenant' => $proposal->id->tenant->value, 'id' => $proposal->actionId->value])->fetchColumn();
        if ($hash !== $proposal->inputHash->value) { throw new IntegrityConflict('Saved action changed'); }
        return new ActionView(new ActionReceipt($proposal->actionId, $this->completed ? 'completed' : 'created', 1), $proposal->definition, 'status');
    }
}
