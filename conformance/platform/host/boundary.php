<?php

declare(strict_types=1);
require_once __DIR__.'/Fixture.php';

use AgenToolsPlatform\Conformance\Host\Fixture;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, TenantId};
use AgenToolsPlatform\Laravel\Host\{ContextState, Envelope};
use AgenToolsPlatform\Laravel\Managed\Input;
use AgenToolsPlatform\Tests\Harness as H;

H::run([
    'host.trusted-handle-no-browser-authority' => static function (): void {
        $f = new Fixture();
        H::rejects(fn () => $f->boundary->open(new Input('allow:true')), ContractViolation::class, ErrorCode::Unauthenticated);
        H::rejects(fn () => new Input('input-a', allow: true), Error::class);
        $f->host->allowed = false;
        H::rejects(fn () => $f->boundary->open(new Input('input-a')), ContractViolation::class, ErrorCode::AccessDenied);
    },
    'host.envelope-required-fields' => static function (): void {
        $f = new Fixture(); $e = $f->envelope; $id = $e->input->context->identity; $s = $e->state->toArray();
        H::check($id->tenant->value === 'school-a' && $id->actorRef->value === 'teacher-1' && $id->actingAs === null
            && $id->subjectRef?->value === 'student-1' && $id->consentRef?->value === 'consent-1'
            && $id->authorizationRef->value === 'authorization-1' && $e->input->context->purpose === 'education.report');
        H::check($s['context']['revision'] === 1 && $s['context']['snapshot_ref']['id'] === 'snapshot-1'
            && $s['education_session_ref']['id'] === 'education-session-1' && $s['realtime_session_ref'] === null
            && $s['traceparent'] === null && $s['locale'] === 'en' && $s['capability'] === ['report.read', 'report.amend']
            && $s['policy_versions'] === ['host' => 'policy-1'] && isset($s['retention_policy_ref'], $s['residency_policy_ref']));
        H::check($e->input->payload->utteranceRef === null && $e->input->payload->conversationRef?->value === 'conversation-1'
            && $e->input->source->eventId === 'msg-1' && $e->input->context->correlation->traceId === 'correlation-1');
        foreach ([0, -1] as $revision) {
            H::rejects(fn () => new ContextState($f->host->ref('s'), $revision, 'en', $f->host->ref('r'), $f->host->ref('p'), ['host'=>'v1'], ['read']), InvalidArgumentException::class);
        }
        H::rejects(fn () => new ContextState($f->host->ref('s'), 1, 'en', new Ref(new TenantId('school-b'),'r'), $f->host->ref('p'), ['host'=>'v1'], ['read']), ContractViolation::class);
    },
    'host.worker-tenant-reset' => static function (): void {
        $f = new Fixture(); $a = $f->envelope;
        try { H::check($f->reports->read($f->host->ref('report-1'))->rawResultRef->tenant->value === 'school-a'); }
        finally { $f->boundary->reset(); }
        H::check($f->host->active === null);
        $b = $f->boundary->open(new Input('input-b'));
        H::check($f->adapter($b)->read($f->host->ref('report-1'))->rawResultRef->tenant->value === 'school-b');
        H::rejects(fn () => $f->adapter($b)->read(new Ref($a->input->context->identity->tenant, 'report-1')), ContractViolation::class);
        $f->boundary->reset(); H::check($f->host->resets === 2 && $f->host->active === null);
    },
    'host.slice-tenant-purpose-expiry-revoke' => static function (): void {
        foreach (['sliceTenant' => 'school-b', 'slicePurpose' => 'billing.write', 'expiry' => '2026-11-03T07:00:00Z', 'sliceAllowed' => false] as $field => $value) {
            $f = new Fixture(); $f->host->$field = $value;
            H::rejects(fn () => $f->boundary->open(new Input('input-a')), ContractViolation::class);
            H::rejects(fn () => $f->boundary->assertCurrent($f->envelope), ContractViolation::class);
        }
    },
    'host.current-context-revision-and-identity' => static function (): void {
        foreach (['actor'=>'teacher-2','subject'=>'student-2','consent'=>'consent-2','policy'=>'policy-2','revision'=>2,'emergency'=>true,'allowed'=>false] as $field => $value) {
            $f = new Fixture(); $f->host->$field = $value;
            H::rejects(fn () => $f->reports->read($f->host->ref('report-1')), ContractViolation::class);
            H::check($f->api->writes === 0);
        }
        $f = new Fixture(); $f->host->fixture['requests']['input-a']['purpose'] = 'money.write';
        H::rejects(fn () => $f->boundary->assertCurrent($f->envelope), ContractViolation::class);
        $f = new Fixture(); $f->host->fixture['requests']['input-a']['tenant'] = 'school-b';
        H::rejects(fn () => $f->boundary->assertCurrent($f->envelope), ContractViolation::class);
    },
    'host.forged-envelope-refused' => static function (): void {
        $f = new Fixture(); $e = $f->envelope;
        $forged = new Envelope($e->handle, $e->input, $e->state, [], $e->fingerprint);
        H::rejects(fn () => $f->boundary->assertCurrent($forged), ContractViolation::class, ErrorCode::StaleContext);
    },
]);
