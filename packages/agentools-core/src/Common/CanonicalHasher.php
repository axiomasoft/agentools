<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

final readonly class CanonicalHasher
{
    public function __construct(private CanonicalJson $json)
    {
    }

    public function hash(TenantId $tenant, string $domain, mixed $payload): PayloadHash
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $domain) !== 1) {
            throw new \InvalidArgumentException('Hash domain is required');
        }
        $this->assertJsonValue($payload);
        return new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:' . hash('sha256', $this->json->canonicalize([
            'revision' => 'platform/v1', 'tenant_id' => $tenant->value,
            'domain' => $domain, 'payload' => $payload,
        ])));
    }

    private function assertJsonValue(mixed $value): void
    {
        if (is_int($value) && ($value > 9007199254740991 || $value < -9007199254740991)) {
            throw new \InvalidArgumentException('Integer outside the interoperable JSON range');
        }
        if (is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('Non-finite JSON number');
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertJsonValue($item);
            }
        } elseif ($value !== null && !is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            throw new \InvalidArgumentException('Only JSON values can be hashed');
        }
    }
}
