<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Inbox;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Security\TrustedInput;
use AgenToolsPlatform\Postgres\Runtime\Connection;

final readonly class SourceAdmission implements IntakeAdmission
{
    public function assertCurrent(Connection $db, TrustedInput $input): void
    {
        $row=$db->row('SELECT s.closed,s.watermark,b.source_revision FROM '.$db->table('input_source_bindings').' b
            JOIN '.$db->table('source_fences').' s ON s.tenant_id=b.tenant_id AND s.id=b.source_ref
            WHERE b.tenant_id=:tenant AND b.id=:id AND b.session_id=:session FOR UPDATE OF s',
            ['tenant'=>$input->context->session->tenant->value,'id'=>$input->payload->inputRef->value,
                'session'=>$input->context->session->value]);
        if ($row===null || $row['closed']!==false
            || Connection::number($row,'watermark')!==Connection::number($row,'source_revision')) {
            throw new ContractViolation(ErrorCode::Cancelled);
        }
    }
}
