<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

final readonly class DefaultSettings
{
    public function __construct(public Tone $tone, public string $displayName)
    {
        self::assertName($displayName, 80);
    }
    public static function assertName(string $value, int $maxBytes): void
    {
        if (trim($value) === '' || strlen($value) > $maxBytes || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match('//u', $value) !== 1) { throw new \InvalidArgumentException('Bounded display name required'); }
    }
    /**
     * @return array<string, string>
     */
    public function toArray(): array { return ['tone'=>$this->tone->value, 'display_name'=>$this->displayName]; }
}
