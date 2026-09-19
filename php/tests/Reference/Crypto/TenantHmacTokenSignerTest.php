<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Crypto;

use AgenTools\Reference\Crypto\TenantHmacTokenSigner;
use PHPUnit\Framework\TestCase;

final class TenantHmacTokenSignerTest extends TestCase
{
    /** INT-002 §2; tenant-scoped secrets do not verify across tenants. */
    public function testCrossTenantSecretsDoNotVerifyEachOther(): void
    {
        $tenantA = new TenantHmacTokenSigner('tenant-a', 'secret-a');
        $tenantB = new TenantHmacTokenSigner('tenant-b', 'secret-b');
        $payload = '{"tenant":"tenant-a"}';
        $signature = $tenantA->sign($payload);

        self::assertTrue($tenantA->verify($payload, $signature));
        self::assertFalse($tenantB->verify($payload, $signature));
    }
}
