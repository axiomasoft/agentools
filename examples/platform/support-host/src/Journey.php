<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Examples\SupportHost;

use AgenToolsPlatform\Examples\ReadJourney\SupportJourney;
use AgenToolsPlatform\Laravel\Host\{Boundary, Envelope};
use AgenToolsPlatform\Laravel\Managed\Input;

/** Synthetic composition over the existing durable runtime; no additional execution loop. */
final readonly class Journey
{
    private Boundary $boundary;
    private Envelope $envelope;
    public function __construct(public Host $host, public SupportJourney $runtime)
    {
        $host->tenant->assertSame($runtime->tenant);
        $this->boundary = new Boundary($host, $host, $host->hasher);
        $this->envelope = $this->boundary->open(new Input('ticket-request'));
        if ($this->envelope->input->context->identity->actorRef->value !== $runtime->context->identity->actorRef->value) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(\AgenToolsPlatform\Core\Common\ErrorCode::AccessDenied);
        }
        $runtime->contextProvider = function (\AgenToolsPlatform\Core\Security\ExecutionContext $context) use ($host): array {
            $this->current();
            return $host->slices($context, $this->envelope->state);
        };
    }
    public function accept(): void { $this->current(); $this->runtime->accept(); }
    public function advance(): string { $this->current(); return $this->runtime->driveOnce(); }
    public function deliver(): string { $this->current(); return $this->runtime->delivery->deliver(); }
    private function current(): void { $this->boundary->assertCurrent($this->envelope); }
}
