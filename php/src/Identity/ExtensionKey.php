<?php

declare(strict_types=1);

namespace AgenTools\Identity;

/** A pure parser for a key that a producer claims is AgenTools data. */
final readonly class ExtensionKey
{
    private const OWN_KEY_PATTERN = '/^dev\.agentools\/(?:[A-Za-z0-9]|[A-Za-z0-9][A-Za-z0-9._-]*[A-Za-z0-9])$/';

    private function __construct(public string $value)
    {
    }

    public static function action(): self
    {
        return new self(ExtensionIdentity::DATA_KEY);
    }

    public static function parseOwn(string $key): self
    {
        if (!str_starts_with($key, ExtensionIdentity::VENDOR_PREFIX . '/')) {
            throw new ForeignVendorKey("{$key} does not use the " . ExtensionIdentity::VENDOR_PREFIX . ' vendor prefix.');
        }
        if (preg_match('/(?:-v[0-9]+|\/v[0-9]+)$/', $key) === 1) {
            throw new VersionedDataKey("{$key} puts a version in the extension data key.");
        }
        if (preg_match(self::OWN_KEY_PATTERN, $key) !== 1) {
            throw new InvalidVendorKey("{$key} does not match the AgenTools vendor-key grammar.");
        }
        if ($key !== ExtensionIdentity::DATA_KEY) {
            throw new InvalidVendorKey("{$key} is not the declared AgenTools data key.");
        }

        return new self($key);
    }
}
