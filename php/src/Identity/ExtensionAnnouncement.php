<?php

declare(strict_types=1);

namespace AgenTools\Identity;

final readonly class ExtensionAnnouncement
{
    public function __construct(public ?string $specRevision)
    {
    }

    /** @return array<string, array{specRevision: string}> */
    public static function local(): array
    {
        return [ExtensionIdentity::IDENTIFIER => ['specRevision' => ExtensionIdentity::SPEC_REVISION]];
    }

    /** @param array<string, mixed> $extensions */
    public static function fromExtensions(array $extensions): self
    {
        $settings = $extensions[ExtensionIdentity::IDENTIFIER] ?? null;
        if (!is_array($settings) || !array_key_exists('specRevision', $settings) || !is_string($settings['specRevision'])) {
            return new self(null);
        }

        return new self($settings['specRevision']);
    }

    public function supportsCurrentRevision(): bool
    {
        return $this->specRevision === ExtensionIdentity::SPEC_REVISION;
    }
}
