<?php

declare(strict_types=1);

namespace AgenTools\Tests\Identity;

use AgenTools\Identity\ExtensionKey;
use AgenTools\Identity\ExtensionIdentity;
use AgenTools\Identity\ForeignVendorKey;
use AgenTools\Identity\InvalidVendorKey;
use AgenTools\Identity\VersionedDataKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionKeyTest extends TestCase
{
    /** EXT-7, EXT-8 */
    public function testItParsesTheSingleDeclaredDataKey(): void
    {
        self::assertSame(ExtensionIdentity::DATA_KEY, ExtensionKey::parseOwn(ExtensionIdentity::DATA_KEY)->value);
    }

    /** EXT-6 */
    #[DataProvider('versionedKeys')]
    public function testItRejectsAVersionInTheDataKey(string $key): void
    {
        $this->expectException(VersionedDataKey::class);
        ExtensionKey::parseOwn($key);
    }

    /** @return array<string, array{string}> */
    public static function versionedKeys(): array
    {
        return ['suffix' => ['dev.agentools/action-v1'], 'path' => ['dev.agentools/action/v1']];
    }

    /** EXT-8 */
    public function testItRejectsMalformedOwnVendorKey(): void
    {
        $this->expectException(InvalidVendorKey::class);
        ExtensionKey::parseOwn('dev.agentools/action/');
    }

    /** EXT-10 */
    public function testItRejectsAClaimedForeignVendorKey(): void
    {
        $this->expectException(ForeignVendorKey::class);
        ExtensionKey::parseOwn('acme.crm/action');
    }
}
