<?php

declare(strict_types=1);
require_once dirname(__DIR__, 3).'/examples/platform/support-host/bootstrap.php';
require_once dirname(__DIR__, 3).'/packages/agentools-core/tests/Common/Harness.php';
require_once dirname(__DIR__, 3).'/packages/agentools-core/tests/Common/JcsAdapter.php';

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, TenantId};
use AgenToolsPlatform\Examples\SupportHost\{Host, Tools};
use AgenToolsPlatform\Core\Actions\ActionAccess;
use AgenToolsPlatform\Laravel\Host\Boundary;
use AgenToolsPlatform\Laravel\Managed\Input;
use AgenToolsPlatform\Tests\{Harness as H, JcsAdapter};

H::run([
    'support.context' => static function (): void {
        $host = new Host(new CanonicalHasher(new JcsAdapter()), new TenantId('support-tenant'));
        $boundary = new Boundary($host, $host, $host->hasher);
        $envelope = $boundary->open(new Input('ticket-request'));
        H::check($envelope->input->context->purpose === 'support.read');
        H::check($envelope->state->educationSession === null && $envelope->state->realtimeSession === null);
        H::check($envelope->slices[0]->modelView === 'Ticket T-1 is open.');
        $boundary->assertCurrent($envelope);
        $host->emergency = true;
        H::rejects(fn () => $boundary->assertCurrent($envelope), ContractViolation::class);
    },
    'support.tool-intersection' => static function (): void {
        $tools = new Tools(new JcsAdapter());
        $read = ActionAccess::key($tools->read->ref); $write = ActionAccess::key($tools->update->ref);
        $host = new Host(new CanonicalHasher(new JcsAdapter()), new TenantId('support-tenant'));
        $access = $tools->access([$read], [$read, $write], $host);
        H::check(count($tools->registry->modelView($access)) === 1 && !$tools->read->requiresConfirmation);
        H::check($tools->update->requiresConfirmation);
        H::rejects(fn () => $tools->registry->executionView($tools->update->ref, $access), ContractViolation::class);
        $host->emergency = true;
        H::check($tools->registry->modelView($tools->access([$read], [$read], $host)) === []);
    },
    'support.untrusted-handle' => static function (): void {
        $host = new Host(new CanonicalHasher(new JcsAdapter()), new TenantId('support-tenant'));
        $boundary = new Boundary($host, $host, $host->hasher);
        H::rejects(fn () => $boundary->open(new Input('forged')), ContractViolation::class);
        $envelope = $boundary->open(new Input('ticket-request'));
        $host->revision = 2;
        H::rejects(fn () => $boundary->assertCurrent($envelope), ContractViolation::class);
    },
]);
