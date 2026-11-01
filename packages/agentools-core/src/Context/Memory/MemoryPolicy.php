<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;

interface MemoryPolicy
{
    public function authorize(ExecutionContext $context,Ref $subject,string $operation): void;
    public function consentVersion(ExecutionContext $context,Ref $subject): string;
    public function permitsStorage(ExecutionContext $context,Candidate $candidate): void;
    public function checkEvidence(ExecutionContext $context,Candidate $candidate): void;
    public function accept(ExecutionContext $context,Candidate $candidate): Acceptance;
}
