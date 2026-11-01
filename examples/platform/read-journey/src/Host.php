<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, SessionId};
use AgenToolsPlatform\Core\Security\{HostAuthorization, HostIdentity};

/** Synthetic host authentication/authorization owner, not a platform permission cache. */
final readonly class Host implements HostAuthorization
{
    public function __construct(private SupportJourney $journey) {}
    public function authorize(SessionId $session, string $purpose): HostIdentity
    {
        $j = $this->journey; $j->idle(); $session->assertSame($j->session);
        $row = $j->db->row('SELECT allowed,policy_revision FROM ' . $j->db->table('demo_permissions') . ' WHERE tenant_id=:tenant', ['tenant' => $session->tenant->value]);
        if ($row === null || $row['allowed'] !== true || $purpose !== 'support.read') { throw new ContractViolation(ErrorCode::AccessDenied); }
        return new HostIdentity($session->tenant, $j->ref('actor'), null, $j->ref('host-authorization'),
            \AgenToolsPlatform\Postgres\Runtime\Connection::text($row, 'policy_revision'), $j->ref('subject'), $j->ref('consent'), $j->ref('agent'));
    }
}
