<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,PayloadHash,Ref};

/** Trusted compiler/manifest adapter. No model-data decoder may implement this boundary. */
interface SnapshotEvidence
{
    public function ref(): Ref;
    public function revision(): int;
    public function hash(): PayloadHash;
    public function deployment(): Ref;
    public function rendererVersion(): string;
    public function renderedHash(): PayloadHash;
    public function sensitive(): bool;
    /** @return list<SourcePin> */
    public function sources(): array;
    public function assertBound(ExecutionContext $context,CanonicalHasher $hasher): void;
}
