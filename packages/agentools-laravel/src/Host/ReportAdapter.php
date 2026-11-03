<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Host;

use AgenToolsPlatform\Core\Actions\Effects\{LookupResult, OwnerPort, Request, Strategy};
use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, IntegrityConflict, Ref};
use AgenToolsPlatform\Core\Context\ToolResultView;
use AgenToolsPlatform\Core\Security\BoundaryClock;

/** Scoped bridge for the existing Actions effect service; never a dispatcher or default binding. */
final readonly class ReportAdapter implements OwnerPort
{
    public function __construct(private Boundary $boundary, private Envelope $envelope,
        private EducationApi $owner, private ReportEvidence $evidence, private CanonicalHasher $hasher,
        private BoundaryClock $clock) {}

    public function namespace(): string { return 'edufy.official-report'; }
    public function strategy(): Strategy { return Strategy::NativeKey; }

    public function read(Ref $report): ToolResultView
    {
        $this->boundary->assertCurrent($this->envelope);
        $this->requireCapability('report.read');
        $tenant = $this->envelope->input->context->identity->tenant;
        $tenant->assertSame($report->tenant);
        $view = $this->owner->readReport($this->envelope, $report);
        $tenant->assertSame($view->rawResultRef->tenant);
        $this->boundary->assertCurrent($this->envelope);
        if (strlen($view->modelView) > 16384
            || new \DateTimeImmutable($view->freshAt->value) > new \DateTimeImmutable($this->clock->now()->value)) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        return $view;
    }

    public function execute(Request $request): LookupResult
    {
        $this->check($request);
        $this->requireCapability('report.amend');
        $this->evidence->assertCurrent($this->envelope, $request);
        $result = $this->owner->amendReport($this->envelope, $request);
        $result->assertRequest($request);
        return $result;
    }

    public function lookup(Request $request): LookupResult
    {
        $this->check($request);
        $this->requireCapability('report.read');
        $result = $this->owner->lookupReport($this->envelope, $request);
        $result->assertRequest($request);
        return $result;
    }

    private function requireCapability(string $capability): void
    {
        if (!in_array($capability, $this->envelope->state->capability, true)) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }

    private function check(Request $request): void
    {
        $this->boundary->assertCurrent($this->envelope);
        $context = $this->envelope->input->context;
        $context->identity->tenant->assertSame($request->action->tenant);
        if ($request->ownerNamespace !== $this->namespace() || $request->strategy !== $this->strategy()
            || $request->context->scopeHash($this->hasher)->value !== $context->scopeHash($this->hasher)->value) {
            throw new IntegrityConflict('Owner request scope/namespace/strategy conflict');
        }
    }
}
