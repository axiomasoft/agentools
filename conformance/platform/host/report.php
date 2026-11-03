<?php

declare(strict_types=1);
require_once __DIR__.'/Fixture.php';

use AgenToolsPlatform\Conformance\Host\Fixture;
use AgenToolsPlatform\Core\Actions\Effects\{LookupStatus, Request, Strategy};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, Ref, TenantId};
use AgenToolsPlatform\Laravel\Managed\Input;
use AgenToolsPlatform\Tests\Harness as H;

H::run([
    'host.allowed-read-model-projection' => static function (): void {
        $f = new Fixture(); $view = $f->reports->read($f->host->ref('report-1'));
        H::check($view->modelView === $f->host->fixture['report_model_view']);
        H::check(!str_contains($view->modelView, $f->host->fixture['report_private_fact'])
            && $view->rawResultRef->value !== $view->modelViewRef->value && $view->auditViewRef->value !== $view->userViewRef->value);
        $block = $view->block($f->host->ref('tool-1'), $f->host->ref('report-1'), 'v1', $f->host->ref('edufy'),
            'education.report', new \AgenToolsPlatform\Core\Common\UtcInstant($f->host->expiry), 'report.read', 1, $f->hasher);
        H::check($block->trust === 'untrusted' && $block->provenance->rawResultRef?->value === 'owner:raw-report');
        H::rejects(fn () => $f->reports->read($f->host->ref('other-report')), ContractViolation::class, ErrorCode::AccessDenied);
        $f->api->beforeReadReturn = static function () use ($f): void { $f->host->allowed = false; };
        H::rejects(fn () => $f->reports->read($f->host->ref('report-1')), ContractViolation::class, ErrorCode::AccessDenied);
    },
    'host.confirmable-write-result-ref' => static function (): void {
        $f = new Fixture(); $request = $f->request();
        H::rejects(fn () => $f->reports->execute($request), ContractViolation::class, ErrorCode::ConfirmationRequired);
        H::check($f->api->writes === 0); $f->approve($request);
        $result = $f->reports->execute($request); $result->assertRequest($request);
        H::check($result->status === LookupStatus::Succeeded && $result->receipt?->value === 'owner:report-revision-2');
        H::check($f->api->officialFacts['school-a'] === 'Synthetic approved fact');
        H::check($f->reports->execute($request) === $result && $f->api->writes === 1 && $f->evidence->checks >= 4);
    },
    'host.write-current-owner-race' => static function (): void {
        $f = new Fixture(); $r = $f->request(); $f->approve($r);
        $f->api->beforeMutation = static function () use ($f): void { $f->host->allowed = false; };
        H::rejects(fn () => $f->reports->execute($r), ContractViolation::class, ErrorCode::AccessDenied);
        H::check($f->api->writes === 0);
        $f = new Fixture(); $f->host->capability = ['report.read'];
        $f->envelope = $f->boundary->open(new Input('input-a'));
        $f->reports = $f->adapter($f->envelope); $r = $f->request(); $f->approve($r);
        H::rejects(fn () => $f->reports->execute($r), ContractViolation::class, ErrorCode::AccessDenied);
        H::check($f->api->writes === 0);
    },
    'host.confirmation-binding-expiry-fence' => static function (): void {
        foreach (['expiry', 'epoch', 'cancelled', 'input', 'effect'] as $fault) {
            $f = new Fixture(); $r = $f->request(); $f->approve($r);
            if ($fault === 'expiry') { $f->host->instant = '2026-11-03T07:30:00Z'; }
            if ($fault === 'epoch') { $f->evidence->epoch = 2; }
            if ($fault === 'cancelled') { $f->evidence->cancelled = true; }
            if ($fault === 'input') { $r = $f->request(fact: 'changed after approval'); }
            if ($fault === 'effect') { $r = new Request($r->action, 'other-effect', $r->ownerNamespace,
                $r->operationKey, $r->requestHash, $r->requestRef, $r->context, $r->requestedEpoch, $r->strategy); }
            H::rejects(fn () => $f->reports->execute($r), ContractViolation::class, ErrorCode::ConfirmationInvalid);
            H::check($f->api->writes === 0);
        }
        $f = new Fixture(); $r = $f->request();
        H::rejects(fn () => $f->evidence->affirmative($f->envelope, $r, $f->host->ref('other-actor'), $f->host->ref('event'), 'ui', $f->host->now()), ContractViolation::class);
    },
    'host.owner-revision-and-hash-conflict' => static function (): void {
        $f = new Fixture(); $r = $f->request(); $f->approve($r); $f->reports->execute($r);
        $changed = $f->request(fact: 'different fact'); $f->approve($changed);
        H::rejects(fn () => $f->reports->execute($changed), IntegrityConflict::class);
        $stale = $f->request('operation-2'); $f->approve($stale);
        H::rejects(fn () => $f->reports->execute($stale), ContractViolation::class, ErrorCode::OptimisticConflict);
        H::check($f->api->writes === 1);
        $next = $f->request('operation-2', version: 2); $f->approve($next);
        H::check($f->reports->execute($next)->receipt?->value === 'owner:report-revision-3' && $f->api->writes === 2);
    },
    'host.unknown-lookup-lost-ack' => static function (): void {
        $f = new Fixture(); $r = $f->request();
        $unknown = $f->reports->lookup($r); H::check($unknown->status === LookupStatus::Unknown && $unknown->receipt === null);
        $f->approve($r); $f->api->loseReply = true;
        H::rejects(fn () => $f->reports->execute($r), RuntimeException::class);
        H::check($f->api->writes === 1);
        $f->host->instant = '2026-11-03T07:31:00Z'; // Approval expired; status lookup remains read-only.
        $known = $f->reports->lookup($r); H::check($known->status === LookupStatus::Succeeded && $f->api->writes === 1);
        $f->host->allowed = false;
        H::rejects(fn () => $f->reports->lookup($r), ContractViolation::class, ErrorCode::AccessDenied);
    },
    'host.report-tenant-and-namespace-binding' => static function (): void {
        $f = new Fixture(); $r = $f->request(); $f->approve($r);
        H::rejects(fn () => $f->reports->read(new Ref(new TenantId('school-b'), 'report-1')), ContractViolation::class);
        $wrong = new Request($r->action, $r->effectKey, 'other.owner', $r->operationKey, $r->requestHash, $r->requestRef,
            $r->context, $r->requestedEpoch, Strategy::NativeKey);
        H::rejects(fn () => $f->reports->execute($wrong), IntegrityConflict::class);
        H::check($f->api->writes === 0);
    },
]);
