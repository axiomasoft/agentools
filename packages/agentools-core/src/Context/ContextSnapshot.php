<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;

final readonly class ContextSnapshot
{
    public function __construct(public Ref $ref, public int $revision, public PayloadHash $contentHash, public PayloadHash $scopeHash)
    {
        if ($revision < 1 || $revision > 9007199254740991) {
            throw new \InvalidArgumentException('Snapshot revision must be positive');
        }
    }

    public function assertScope(ExecutionContext $context, CanonicalHasher $hasher): void
    {
        $this->ref->tenant->assertSame($context->identity->tenant);
        $this->scopeHash->assertSame($context->scopeHash($hasher));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['snapshot_ref' => $this->ref->toArray(), 'revision' => $this->revision,
            'content_hash' => $this->contentHash->value, 'scope_hash' => $this->scopeHash->value];
    }
}
