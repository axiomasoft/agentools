<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,IntegrityConflict,Ref};

/** Closed default logging projection. Untrusted nested fields, exception messages/stacks/previous never enter it. */
final class LogProjection
{
    /**
     * @param array<string,mixed> $untrusted
     * @return array<string,mixed>
     */
    public static function record(ExecutionContext $context,LogCode $code,TraceLinks $links,array $untrusted=[],?\Throwable $error=null): array
    {
        $values=$links->toArray();
        foreach ($values as $value) {
            if (is_array($value) && isset($value['tenant_id']) && $value['tenant_id']!==$context->identity->tenant->value) { throw new \InvalidArgumentException('Foreign trace link'); }
        }
        $errorCode=match(true) {
            $error===null=>null,
            $error instanceof ContractViolation=>$error->errorCode->value,
            $error instanceof IntegrityConflict=>ErrorCode::IntegrityConflict->value,
            default=>'internal_error',
        };
        return ['tenant_id'=>$context->identity->tenant->value,'session_id'=>$context->session->value,'correlation'=>$context->correlation->toArray(),
            'code'=>$code->value,'links'=>$values,'error_code'=>$errorCode,
            'redacted'=>$untrusted!==[] || $error!==null];
    }
}
