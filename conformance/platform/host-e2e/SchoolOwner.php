<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Conformance\HostE2e;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, UtcInstant};
use AgenToolsPlatform\Core\Context\{ContextAccess, ContextBlock};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Examples\ReadJourney\SupportJourney;

/** Synthetic education helpdesk owner, not an Edufy implementation. */
final class SchoolOwner implements ContextAccess
{
    public bool $allowed = true;
    public bool $emergency = false;
    public function __construct(public readonly SupportJourney $runtime) {}
    public function permits(ExecutionContext $context, ContextBlock $block): bool
    {
        return $this->allowed && !$this->emergency
            && $context->session->tenant->value === $this->runtime->tenant->value
            && $context->identity->actorRef->value === $this->runtime->context->identity->actorRef->value
            && $context->purpose === 'support.read' && $block->purpose === $context->purpose;
    }
    /** @return list<ContextBlock> */
    public function slices(ExecutionContext $context): array
    {
        $j=$this->runtime; $ref=static fn (string $id): Ref => new Ref($j->tenant,$id);
        $block=new ContextBlock($ref('school-slice'), 'evidence', $ref('school-case-1'), '1',
            $ref('school-owner'), 'owner', 'private', 'support.read', $ref('school-approved-view'),
            'School helpdesk case: learner access request is open.', new UtcInstant('2031-01-01T00:00:00Z'),
            false, 1, $j->hasher);
        if (!$this->permits($context,$block)) { throw new ContractViolation(ErrorCode::AccessDenied); }
        return [$block];
    }
    public function attach(): void
    {
        $j=$this->runtime;
        $j->contextProvider = fn (ExecutionContext $context): array => $this->slices($context);
        $j->barrier = function (string $point): void { $this->current(); };
    }
    public function current(): void { $this->slices($this->runtime->context); }
}
