<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\EdufyHost;

use AgenToolsPlatform\Core\Actions\Effects\{LookupResult, LookupStatus, Request};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, Ref};
use AgenToolsPlatform\Core\Context\{Classification, ToolResultView};
use AgenToolsPlatform\Laravel\Host\{Boundary, EducationApi, Envelope};

/** Synthetic Edufy public API owner. Only THIS stub owns the local official facts. */
final class SchoolApi implements EducationApi
{
    /** @var array<string, string> Immutable host-side input handles; never platform raw arguments. */
    public array $inputs = [];
    /** @var array<string, array{hash: string, result: LookupResult}> */
    private array $receipts = [];
    /** @var array<string, int> */
    private array $reportVersions = [];
    /** @var array<string, string> Synthetic official facts, owned only here. */
    public array $officialFacts = [];
    public int $writes = 0;
    public bool $loseReply = false;
    public ?\Closure $beforeMutation = null;
    public ?\Closure $beforeReadReturn = null;
    public function __construct(private SchoolHost $host, private Boundary $boundary, private SavedEvidence $evidence) {}
    public function readReport(Envelope $envelope, Ref $report): ToolResultView
    {
        $this->boundary->assertCurrent($envelope);
        $tenant = $envelope->input->context->identity->tenant; $tenant->assertSame($report->tenant);
        if ($report->value !== $this->host->fixture['report_ref']) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $view = new ToolResultView($this->host->ref('owner:raw-report'), $this->host->ref('owner:model-report'),
            $this->host->fixture['report_model_view'], $this->host->ref('owner:user-report'), $this->host->ref('owner:audit-report'),
            Classification::Restricted, $this->host->now(), [$report]);
        if ($this->beforeReadReturn !== null) { ($this->beforeReadReturn)(); }
        return $view;
    }
    public function amendReport(Envelope $envelope, Request $request): LookupResult
    {
        if ($this->beforeMutation !== null) { ($this->beforeMutation)(); }
        // Synthetic atomic owner boundary: re-check after entry, immediately before fact mutation.
        $this->boundary->assertCurrent($envelope); $this->evidence->assertCurrent($envelope, $request);
        $tenant = $envelope->input->context->identity->tenant;
        $tenant->assertSame($request->action->tenant); $tenant->assertSame($request->requestRef->tenant);
        $key = $tenant->value.':'.$request->operationKey;
        $input = $this->inputs[$tenant->value.':'.$request->requestRef->value] ?? null;
        if ($input === null) { throw new ContractViolation(ErrorCode::InvalidInput); }
        $payload = json_decode($input, false, 512, JSON_THROW_ON_ERROR);
        $request->requestHash->assertSame($this->host->hasher->hash($tenant, 'action-input', $payload));
        if (isset($this->receipts[$key])) {
            if ($this->receipts[$key]['hash'] !== $request->requestHash->value) { throw new IntegrityConflict('Owner operation hash conflict'); }
            return $this->receipts[$key]['result'];
        }
        if (!$payload instanceof \stdClass || count(get_object_vars($payload)) !== 3
            || ($payload->report_ref ?? null) !== $this->host->fixture['report_ref']
            || !is_string($payload->fact ?? null) || strlen($payload->fact) > 1024
            || ($payload->expected_revision ?? null) !== ($this->reportVersions[$tenant->value] ?? 1)) {
            throw new ContractViolation(ErrorCode::OptimisticConflict);
        }
        $this->reportVersions[$tenant->value] = $payload->expected_revision + 1;
        $this->officialFacts[$tenant->value] = $payload->fact;
        ++$this->writes;
        $result = new LookupResult(LookupStatus::Succeeded, new Ref($tenant, $request->operationKey),
            $request->ownerNamespace, $request->requestHash, $this->host->ref('owner:report-revision-'.$this->reportVersions[$tenant->value]));
        $this->receipts[$key] = ['hash' => $request->requestHash->value, 'result' => $result];
        if ($this->loseReply) { throw new \RuntimeException('Scripted owner ACK loss'); }
        return $result;
    }
    public function lookupReport(Envelope $envelope, Request $request): LookupResult
    {
        $this->boundary->assertCurrent($envelope);
        $envelope->input->context->identity->tenant->assertSame($request->action->tenant);
        $envelope->input->context->identity->tenant->assertSame($request->requestRef->tenant);
        $key = $request->action->tenant->value.':'.$request->operationKey;
        if (isset($this->receipts[$key])) {
            if ($this->receipts[$key]['hash'] !== $request->requestHash->value) { throw new IntegrityConflict('Owner operation hash conflict'); }
            return $this->receipts[$key]['result'];
        }
        return new LookupResult(LookupStatus::Unknown, new Ref($request->action->tenant, $request->operationKey), $request->ownerNamespace, $request->requestHash);
    }
}
