<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Cache;
use AgenToolsPlatform\Core\Budget\AdmissionScope;
use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};

final readonly class WarmRequest
{
    /** @param array<string,mixed> $usageMetadata */
    public function __construct(public Ref $operation,public AdmissionScope $admission,public array $usageMetadata,
        public UtcInstant $expiresAt,public int $estimate,public int $popularity,public string $operationKind='create')
    { if (!in_array($operationKind,['create','refresh'],true)) { throw new \InvalidArgumentException('Explicit warm operation required'); } }
}
