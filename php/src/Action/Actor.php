<?php

declare(strict_types=1);

namespace AgenTools\Action;

final readonly class Actor
{
    public function __construct(public string $type, public string $id)
    {
        if ($type === '' || $id === '') {
            throw new \InvalidArgumentException('An actor type and id are required.');
        }
    }

    /** @return array{type: string, id: string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}
