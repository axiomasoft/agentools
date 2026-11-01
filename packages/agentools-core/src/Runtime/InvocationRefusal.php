<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict};

/** A typed local denial observed before entering provider IO, never inferred from its exception. */
final readonly class InvocationRefusal
{
    private function __construct(public ErrorCode $code, private bool $integrityConflict) {}

    public static function denied(ContractViolation|IntegrityConflict $error): self
    {
        return new \AgenToolsPlatform\Core\Runtime\InvocationRefusal($error instanceof ContractViolation ? $error->errorCode : ErrorCode::IntegrityConflict,
            $error instanceof IntegrityConflict);
    }

    /** @return array{error_code: string, error_type: string} */
    public function toArray(): array
    {
        return ['error_code' => $this->code->value, 'error_type' => $this->integrityConflict ? 'integrity_conflict' : 'contract_violation'];
    }

    public static function restore(InvocationCheckpoint $saved): self
    {
        $data = $saved->data['refusal'] ?? null;
        if ($saved->stage() !== 'refused' || !is_array($data) || !is_string($data['error_code'] ?? null)
            || !in_array($data['error_type'] ?? null, ['integrity_conflict', 'contract_violation'], true)
            || ($data['error_type'] === 'integrity_conflict' && $data['error_code'] !== ErrorCode::IntegrityConflict->value)
            || ($saved->data['request_count'] ?? null) !== 0 || ($saved->data['possible_cost'] ?? null) !== false) {
            throw new \UnexpectedValueException('Invalid saved pre-provider refusal');
        }
        return new \AgenToolsPlatform\Core\Runtime\InvocationRefusal(ErrorCode::from($data['error_code']), $data['error_type'] === 'integrity_conflict');
    }

    public function exception(): ContractViolation|IntegrityConflict
    {
        return $this->integrityConflict ? new \AgenToolsPlatform\Core\Common\IntegrityConflict(ErrorCode::IntegrityConflict->value)
            : new \AgenToolsPlatform\Core\Common\ContractViolation($this->code);
    }
}
