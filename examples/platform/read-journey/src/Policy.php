<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Actions\{ActionAccess, ActionPolicy};
use AgenToolsPlatform\Core\Context\{ContextAccess, ContextBlock};
use AgenToolsPlatform\Core\Security\ExecutionContext;

final readonly class Policy implements ActionPolicy, ContextAccess
{
    public function __construct(private SupportJourney $journey) {}
    public function current(ExecutionContext $context): ActionAccess
    {
        $j = $this->journey; $context->assertCurrent($j->host);
        $row = $j->db->row('SELECT tool_allowed FROM ' . $j->db->table('demo_permissions') . ' WHERE tenant_id=:tenant', ['tenant' => $context->session->tenant->value]);
        $set = $row !== null && $row['tool_allowed'] === true ? [ActionAccess::key($j->readDefinition->ref)] : [];
        return new ActionAccess($set, $set, $set, $set, $set, ['support.ticket.read']);
    }
    public function permits(ExecutionContext $context, ContextBlock $block): bool
    {
        $context->assertCurrent($this->journey->host);
        $row = $this->journey->db->row('SELECT context_allowed FROM ' . $this->journey->db->table('demo_permissions') . ' WHERE tenant_id=:tenant', ['tenant' => $context->session->tenant->value]);
        return $row !== null && $row['context_allowed'] === true && $block->purpose === $context->purpose;
    }
}
