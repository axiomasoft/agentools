<?php

declare(strict_types=1);

namespace AgenTools\Tool;

/** The data carried exclusively in _meta["dev.agentools/action"]. */
final readonly class ActionProfile
{
    /**
     * @param array<int, mixed>|null $permissionsHint
     * @param array<string, mixed> $extensionMembers
     */
    public function __construct(
        public string $sideEffect,
        public ?string $confirmation = null,
        public ?bool $idempotentCommit = null,
        public ?array $permissionsHint = null,
        public ?string $contentOrigin = null,
        public array $extensionMembers = [],
    ) {
        if (!self::isOpenValue($sideEffect)) {
            throw new \InvalidArgumentException('side_effect must be a core or product extension value.');
        }
        if ($confirmation !== null && !self::isOpenValue($confirmation)) {
            throw new \InvalidArgumentException('confirmation must be a core or product extension value.');
        }
        if ($contentOrigin !== null && !self::isContentOrigin($contentOrigin)) {
            throw new \InvalidArgumentException('content_origin must be a core or product extension value.');
        }
        if ($permissionsHint !== null) {
            foreach ($permissionsHint as $permission) {
                if (!is_string($permission)) {
                    throw new \InvalidArgumentException('permissions_hint must contain only strings.');
                }
            }
        }
        if ($this->baseRequiresConfirmation() && $confirmation === 'none') {
            throw new \InvalidArgumentException('confirmation cannot weaken the required confirmation gate.');
        }
    }

    public function requiresConfirmation(): bool
    {
        if ($this->baseRequiresConfirmation()) {
            return true;
        }

        return in_array($this->confirmation, ['confirm', 'double_confirm'], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter(
            array_merge($this->extensionMembers, [
                'side_effect' => $this->sideEffect,
                'confirmation' => $this->confirmation,
                'idempotent_commit' => $this->idempotentCommit,
                'permissions_hint' => $this->permissionsHint,
                'content_origin' => $this->contentOrigin,
            ]),
            static fn (mixed $value): bool => $value !== null,
        );
    }

    private function baseRequiresConfirmation(): bool
    {
        return !in_array($this->sideEffect, ['read', 'draft'], true);
    }

    private static function isOpenValue(string $value): bool
    {
        return in_array($value, ['read', 'draft', 'write', 'irreversible', 'none', 'confirm', 'double_confirm'], true)
            || preg_match('/^[a-z][a-z0-9]*\.[a-z0-9_]+$/', $value) === 1;
    }

    private static function isContentOrigin(string $value): bool
    {
        return $value === 'untrusted_external' || preg_match('/^[a-z][a-z0-9]*\.[a-z0-9_]+$/', $value) === 1;
    }
}
