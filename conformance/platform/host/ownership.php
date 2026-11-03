<?php

declare(strict_types=1);
require_once __DIR__.'/Fixture.php';

use AgenToolsPlatform\Conformance\Host\Fixture;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Laravel\Managed\Input;
use AgenToolsPlatform\Tests\Harness as H;

H::run([
    'V2ALL-OWNERSHIP-POSITIVE' => static function (): void {
        $f = new Fixture(); $ack = $f->intake->accept($f->envelope);
        $r = $f->request(); $f->approve($r); $owner = $f->reports->execute($r);
        H::check($ack->runId->tenant->value === $owner->operation->tenant->value);
        $technical = json_encode($f->intake->technicalRows(), JSON_THROW_ON_ERROR);
        H::check(!str_contains($technical, 'Synthetic approved fact') && !str_contains($technical, 'report_private_fact')
            && !str_contains($technical, 'explain the report') && $owner->receipt !== null);
        $contract = json_decode(file_get_contents(dirname(__DIR__, 3).'/examples/platform/edufy-host/contract.json'), true, 512, JSON_THROW_ON_ERROR);
        H::check($contract['owners']['Edufy AiCore'] === ['EducationSession','task_orchestration','pedagogical_packs_providers_scorers','official_report_facts','consent_rights_handoff','person_knowledge_map']);
        H::check(isset($contract['owners']['Chatom'], $contract['owners']['ControLLM'], $contract['owners']['host/Tariffler'], $contract['owners']['existing Billing/money owner']));
    },
    'V2ALL-OWNERSHIP-NEGATIVE' => static function (): void {
        $f = new Fixture(); $r = $f->request();
        // A model-generated amendment with valid-looking refs cannot authorize an effect.
        H::rejects(fn () => $f->reports->execute($r), ContractViolation::class, ErrorCode::ConfirmationRequired);
        $f->host->sliceAllowed = false;
        H::rejects(fn () => $f->intake->accept($f->envelope), ContractViolation::class, ErrorCode::AccessDenied);
        H::check($f->intake->count() === 0 && $f->api->writes === 0);
        $contract = json_decode(file_get_contents(dirname(__DIR__, 3).'/examples/platform/edufy-host/contract.json'), true, 512, JSON_THROW_ON_ERROR);
        H::check($contract['runtime_stores_domain_facts'] === false && $contract['direct_owner_tables'] === false
            && $contract['default_stub_binding'] === false && $contract['implementation'] === 'pending_external_owner');
    },
    'V2ALL-OWNERSHIP-FAULT' => static function (): void {
        $f = new Fixture(); $f->host->available = false;
        H::rejects(fn () => $f->boundary->open(new Input('input-a')), ContractViolation::class, ErrorCode::CapabilityUnavailable);
        H::rejects(fn () => $f->reports->lookup($f->request()), ContractViolation::class, ErrorCode::CapabilityUnavailable);
        H::check($f->intake->count() === 0 && $f->api->writes === 0);
        $cr = json_decode(file_get_contents(dirname(__DIR__, 3).'/examples/platform/edufy-host/change-request.json'), true, 512, JSON_THROW_ON_ERROR);
        H::check($cr['id'] === 'EDUFY-AGENTOOLS-HOST-001' && $cr['revision'] === 1
            && $cr['status'] === 'pending_external_owner' && $cr['implemented_here'] === false
            && in_array('host_e2e', $cr['unclaimed'], true) && in_array('live_provider', $cr['unclaimed'], true));
    },
]);
