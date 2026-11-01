<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Actions\{ActionRegistration, ActionView};
use AgenToolsPlatform\Core\Common\{ActionId, ActionReceipt, TenantId};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Actions\{DatedLifecycle, Fixtures as F, Handler};

require_once __DIR__ . '/Fixtures.php';
require_once __DIR__ . '/DatedLifecycle.php';
$cases = [
    'action.outcomes' => static function (): void {
        $json = file_get_contents(dirname(__DIR__, 4) . '/fixtures/platform/v1/actions/outcomes.json');
        if (!is_string($json)) { throw new RuntimeException('Missing required outcome fixture'); }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['cases'] ?? null)) { throw new RuntimeException('Expected fixture cases'); }
        $seen = [];
        foreach ($data['cases'] as $case) {
            if (!is_array($case) || !is_string($case['id'] ?? null) || !is_array($case['events'] ?? null)
                || !is_string($case['status'] ?? null) || !array_key_exists('outcome', $case)) {
                throw new RuntimeException('Invalid fixture case');
            }
            $backend = new DatedLifecycle();
            $id = new ActionId(new TenantId('tenant-a'), 'dated-action');
            foreach ($case['events'] as $event) {
                if (!is_array($event) || !is_string($event['kind'] ?? null)
                    || (isset($event['epoch']) && !is_int($event['epoch']))) { throw new RuntimeException('Invalid fixture event'); }
                $rawPayload = $event['payload'] ?? [];
                if (!is_array($rawPayload)) { throw new RuntimeException('Invalid fixture payload'); }
                $payload = [];
                foreach ($rawPayload as $key => $value) {
                    if (!is_string($key)) { throw new RuntimeException('Named payload required'); }
                    $payload[$key] = $value;
                }
                $backend->append($event['kind'], $id, $payload, $event['epoch'] ?? null);
            }
            $view = $backend->projection($id, new ActionRegistration(F::definition(), new Handler()));
            H::check($view->receipt->wireStatus === $case['status'] && $view->outcome()?->value === $case['outcome'], $case['id']);
            $seen[] = $case['id'];
        }
        H::check($seen === ['action.accepted-pending', 'action.known-success', 'action.known-failure', 'action.unknown-is-not-failed']);
        foreach (['cancelled', 'rejected', 'expired', 'applying', 'awaiting_confirmation'] as $status) {
            $view = new ActionView(new ActionReceipt(new ActionId(new TenantId('a'), 'id'), $status, 1), F::definition()->ref, 'status');
            H::check($view->outcome() === null);
        }
    },
];
$cases += H::loadCases(__DIR__ . '/facade.php');
$cases += H::loadCases(__DIR__ . '/registry.php');
$only = array_values(array_filter($argv, static fn (string $arg): bool => str_starts_with($arg, '--only=')));
if ($only !== []) { $cases = array_intersect_key($cases, array_fill_keys(explode(',', substr($only[0], 7)), true)); }
H::run($cases, 'unit', 'unit');
