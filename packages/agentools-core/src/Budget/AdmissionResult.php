<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Budget;

use AgenToolsPlatform\Core\Common\{Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Usage\Values;

/** Strict owner wire codec; legacy Admission constructor/API is not narrowed. */
final readonly class AdmissionResult
{
    private function __construct(public Admission $admission, public ?UtcInstant $retryAfter) {}
    /** @param array<string,mixed> $d */
    public static function fromArray(TenantId $tenant, array $d): self
    {
        Values::keys($d,['status','admission_ref','reservation_ref','expires_at','owner_revision','limits','reason','retry_after']);
        $status = Values::text($d['status']); $reason = Values::optionalText($d['reason']);
        if (in_array($status,['denied','unavailable','unknown'],true) && $reason === null) { throw new \InvalidArgumentException('Explicit owner failure reason required'); }
        $limits = []; foreach (Values::object($d['limits']) as $key=>$value) { Values::text($key); $limits[$key] = Values::integer($value); }
        $owner = $d['owner_revision']; if (!is_string($owner)) { throw new \InvalidArgumentException('Owner revision string required'); }
        return new \AgenToolsPlatform\Core\Budget\AdmissionResult(new \AgenToolsPlatform\Core\Budget\Admission($status,
            $d['admission_ref'] === null ? null : new \AgenToolsPlatform\Core\Common\Ref($tenant,Values::text($d['admission_ref'])),
            $d['reservation_ref'] === null ? null : new \AgenToolsPlatform\Core\Common\Ref($tenant,Values::text($d['reservation_ref'])),
            $d['expires_at'] === null ? null : new \AgenToolsPlatform\Core\Common\UtcInstant(Values::text($d['expires_at'])),$owner,$limits,$reason),
            $d['retry_after'] === null ? null : new \AgenToolsPlatform\Core\Common\UtcInstant(Values::text($d['retry_after'])));
    }
    public function permits(UtcInstant $now): bool
    { return $this->admission->status === 'admitted' && $this->admission->expiresAt !== null && Values::compare($now,$this->admission->expiresAt) < 0; }
}
