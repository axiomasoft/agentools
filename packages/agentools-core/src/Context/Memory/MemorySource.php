<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

use AgenToolsPlatform\Core\Common\{PayloadHash,Ref};
use AgenToolsPlatform\Core\Context\History\{MessageRef,TextRead};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** External owner: current authorization/consent and CAS/idempotency remain required at every write. */
interface MemorySource
{
    public function queryCandidates(ExecutionContext $context,Ref $subject,int $limit): CandidatePage;
    public function read(ExecutionContext $context,Ref $candidate): CandidateRead;
    public function persist(ExecutionContext $context,Candidate $candidate,int $expectedVersion): void;
    public function duplicate(ExecutionContext $context,PayloadHash $dedupKey): ?Ref;
    public function view(ExecutionContext $context,Candidate $candidate): TextRead;
    public function revokeSource(ExecutionContext $context,MessageRef $source,string $reason): DeleteRequest;
}
