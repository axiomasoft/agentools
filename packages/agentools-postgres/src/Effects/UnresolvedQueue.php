<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Effects;

use AgenToolsPlatform\Core\Common\ActionId;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** Bounded operator candidates, scoped to the authenticated tenant/session. Never dispatches. */
final readonly class UnresolvedQueue
{
    public function __construct(private RepositoryContext $environment) {}

    /** @return list<ActionId> */
    public function candidates(ExecutionContext $context, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) { throw new \InvalidArgumentException('Bounded queue limit required'); }
        $this->environment->authorize($context);
        $db = $this->environment->db;
        $rows = $db->execute('SELECT i.action_ref FROM '.$db->table('effect_intents').' i JOIN '.$db->table('action_submissions').' s
            ON s.tenant_id=i.tenant_id AND s.id=i.action_ref
            WHERE i.tenant_id=:tenant AND s.scope_hash=:scope AND s.actor_ref=:actor AND i.outcome IN (\'pending\',\'unknown\')
            ORDER BY i.created_at,i.id LIMIT '.$limit,
            ['tenant'=>$context->identity->tenant->value,'scope'=>$context->scopeHash($this->environment->hasher)->value,
                'actor'=>$context->identity->actorRef->value])->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['action_ref']) || !is_string($row['action_ref'])) { throw new \UnexpectedValueException('Invalid unresolved candidate'); }
            $result[] = new ActionId($context->identity->tenant,$row['action_ref']);
        }
        return $result;
    }
}
