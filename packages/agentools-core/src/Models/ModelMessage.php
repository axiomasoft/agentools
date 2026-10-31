<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

final readonly class ModelMessage
{
    /**
     * @param array<array-key, ContentPart> $parts
     * @param array<array-key, ToolProposal> $proposals
     */
    public function __construct(public MessageRole $role, public array $parts, public ?string $proposalRef = null,
        public array $proposals = [])
    {
        if (!array_is_list($parts) || !array_is_list($proposals) || ($parts === [] && $proposals === [])
            || (($role === MessageRole::Tool) !== ($proposalRef !== null)) || $proposalRef === ''
            || ($proposals !== [] && $role !== MessageRole::Assistant)) {
            throw new \InvalidArgumentException('Messages need content; proposals belong to assistant and results need a proposal reference');
        }
    }
}
