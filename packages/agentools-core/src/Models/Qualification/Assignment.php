<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Qualification;
use AgenToolsPlatform\Core\Common\{PayloadHash, Ref, TenantId};
/** Stable session assignment. A new rollout never rewrites this record. */
final readonly class Assignment
{
    public function __construct(public Ref $session, public Ref $policy, public Ref $snapshot,
        public PayloadHash $tupleHash, public bool $canary, public string $reason)
    {
        $session->tenant->assertSame($policy->tenant); $session->tenant->assertSame($snapshot->tenant);
        if ($reason === '') { throw new \InvalidArgumentException('Assignment audit reason required'); }
    }
    /** @return array<string,mixed> */
    public function toArray(): array
    { return ['session'=>$this->session->value,'policy'=>$this->policy->value,'snapshot'=>$this->snapshot->value,'tuple_hash'=>$this->tupleHash->value,'canary'=>$this->canary,'reason'=>$this->reason]; }
    /** @param array<string,mixed> $data */
    public static function fromArray(TenantId $tenant, array $data): self
    {
        if (!isset($data['canary']) || !is_bool($data['canary'])) { throw new \InvalidArgumentException('Explicit canary flag required'); }
        return new \AgenToolsPlatform\Core\Models\Qualification\Assignment(new \AgenToolsPlatform\Core\Common\Ref($tenant,Data::text($data,'session')),new \AgenToolsPlatform\Core\Common\Ref($tenant,Data::text($data,'policy')),new \AgenToolsPlatform\Core\Common\Ref($tenant,Data::text($data,'snapshot')),
            new \AgenToolsPlatform\Core\Common\PayloadHash(Data::text($data,'tuple_hash')),$data['canary'],Data::text($data,'reason'));
    }
}
