<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Models;

use AgenToolsPlatform\Core\Common\{InvocationId, PayloadHash, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Models\{Capability, CapabilityEvidence, Deployment, Evidence, ModelMessage, MessageRole, ContentPart, ModelRequest, Support};

final class ModelFixtures
{
    public static function now(): UtcInstant { return new UtcInstant('2026-10-31T12:00:00Z'); }
    public static function ref(string $id, string $tenant = 'alpha'): Ref { return new Ref(new TenantId($tenant), $id); }
    public static function invocation(): InvocationId { return new InvocationId(new TenantId('alpha'), 'invocation-1'); }
    public static function deployment(string $tenant = 'alpha', string $account = 'account-A', string $region = 'eu'): Deployment
    {
        $capabilities = [];
        foreach (Capability::cases() as $capability) {
            $capabilities[$capability->value] = new CapabilityEvidence(Support::Supported, Evidence::Documented,
                new UtcInstant('2026-10-30T00:00:00Z'), new UtcInstant('2026-11-01T00:00:00Z'), 'local-contract');
        }
        return new Deployment(self::ref('deployment-1', $tenant), 'provider', $account, $region, 'model-1', $capabilities);
    }
    public static function request(): ModelRequest
    {
        return new ModelRequest(self::invocation(), [new ModelMessage(MessageRole::User, [ContentPart::text('hello')])],
            self::ref('snapshot-1'), new PayloadHash('sha-256:' . str_repeat('a', 64)), self::ref('credential-1'),
            new UtcInstant('2026-10-31T12:00:00.000001Z'));
    }
    /** @param array<array-key, mixed> $object */
    public static function string(array $object, string $key): string
    {
        $value = $object[$key] ?? null;
        if (!is_string($value)) { throw new \RuntimeException('Missing fixture string: ' . $key); }
        return $value;
    }
    /** @return array<string, mixed> */
    public static function fixture(string $id): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/fixtures/platform/v1/models/transport.json'), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['cases'] ?? null)) { throw new \RuntimeException('Missing model fixtures'); }
        foreach ($data['cases'] as $case) {
            if (is_array($case) && ($case['id'] ?? null) === $id) {
                /** @var array<string, mixed> $case */
                return $case;
            }
        }
        throw new \RuntimeException('Required shared case missing: ' . $id);
    }
}
