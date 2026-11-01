<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\ContextRevision;

final readonly class Revisions implements ContextRevision
{
    public function __construct(private SupportJourney $journey) {}
    public function current(ExecutionContext $context, Ref $snapshot): int
    {
        $this->journey->idle(); $context->session->tenant->assertSame($snapshot->tenant);
        $row = $this->journey->db->row('SELECT revision FROM ' . $this->journey->db->table('demo_snapshots') . ' WHERE tenant_id=:tenant AND id=:id',
            ['tenant' => $snapshot->tenant->value, 'id' => $snapshot->value]);
        if ($row === null) { throw new ContractViolation(ErrorCode::StaleContext); }
        return \AgenToolsPlatform\Postgres\Runtime\Connection::number($row, 'revision');
    }
}
