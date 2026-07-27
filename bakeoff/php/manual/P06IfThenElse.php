<?php
declare(strict_types=1);

namespace Bakeoff\Manual;

final class P06IfThenElse
{
    public function __construct(
        public readonly string $kind,
        public readonly ?string $promptRef = null,
        public readonly ?string $tool = null,
        public readonly ?string $sideEffect = null,
        public readonly ?string $approval = null,
    ) {
        if (!in_array($kind, ['llm', 'tool'], true)) {
            throw new \InvalidArgumentException("kind '$kind' вне enum");
        }
        if ($kind === 'llm' && $promptRef === null) {
            throw new \InvalidArgumentException('prompt_ref обязателен при kind=llm');
        }
        if ($kind !== 'llm' && $tool === null) {
            throw new \InvalidArgumentException('tool обязателен при kind!=llm');
        }
        if ($sideEffect !== null && !in_array($sideEffect, ['read', 'write'], true)) {
            throw new \InvalidArgumentException("side_effect '$sideEffect' вне enum");
        }
        if ($sideEffect === 'write' && $approval === null) {
            throw new \InvalidArgumentException('approval обязателен при side_effect=write (dependentSchemas)');
        }
        if ($approval !== null && !in_array($approval, ['none', 'confirm'], true)) {
            throw new \InvalidArgumentException("approval '$approval' вне enum");
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['kind'],
            $data['prompt_ref'] ?? null,
            $data['tool'] ?? null,
            $data['side_effect'] ?? null,
            $data['approval'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind,
            'prompt_ref' => $this->promptRef,
            'tool' => $this->tool,
            'side_effect' => $this->sideEffect,
            'approval' => $this->approval,
        ], static fn ($v) => $v !== null);
    }
}
