<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Common/bootstrap.php';
require_once dirname(__DIR__) . '/Models/ModelFixtures.php';

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, Ref, RunId, TurnId};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Models\{FinishReason, JsonObject, ModelResponse, ToolProposal};
use AgenToolsPlatform\Core\Profiles\{BundleRef, DefinitionRef};
use AgenToolsPlatform\Core\Runtime\{ContinuationPolicy, ExchangeResult, InvocationPlan};
use AgenToolsPlatform\Tests\{Harness as H, JcsAdapter};
use AgenToolsPlatform\Tests\Models\ModelFixtures as F;

$hasher = new CanonicalHasher(new JcsAdapter());
$plan = static fn (?string $parent = null, ?string $reason = null, int $estimate = 1): InvocationPlan => new InvocationPlan(
    new RunId(F::invocation()->tenant, 'run'), new TurnId(F::invocation()->tenant, 'turn'), 'step', F::request(), F::ref('immutable-request'), F::deployment(),
    new ContextSnapshot(F::request()->contextSnapshotRef, 1, F::request()->contextSnapshotHash, F::request()->contextSnapshotHash),
    new BundleRef(F::ref('bundle'), 1, F::request()->contextSnapshotHash), F::ref('business'), F::ref('destination'),
    ['read' => new DefinitionRef('read.fact', '1', F::request()->contextSnapshotHash)], new ContinuationPolicy(),
    $estimate, $parent, $reason, $hasher);
$cases = [
    'runtime.bounds-and-serial-only' => static function (): void {
        foreach ([static fn () => new ContinuationPolicy(maxInvocations: 0), static fn () => new ContinuationPolicy(maxTools: -1),
            static fn () => new ContinuationPolicy(maxRepeatedErrors: 0), static fn () => new ContinuationPolicy(maxParallelReads: 2),
            static fn () => new ContinuationPolicy(maxEstimatedUnits: -1)] as $invalid) { H::rejects($invalid, InvalidArgumentException::class); }
        H::check((new ContinuationPolicy())->toArray()['maxParallelReads'] === 1);
    },
    'runtime.plan-and-explicit-replacement' => static function () use ($plan): void {
        H::rejects(static fn () => $plan('old', null), InvalidArgumentException::class);
        H::rejects(static fn () => $plan(F::invocation()->value, 'retry'), InvalidArgumentException::class);
        H::rejects(static fn () => $plan(estimate: 100001), InvalidArgumentException::class);
        H::check($plan('old', 'owner-policy')->identity()['parent_invocation'] === 'old');
        H::check($plan()->requestHash->value === $plan()->requestHash->value);
    },
    'runtime.normalization-and-mapping-integrity' => static function () use ($plan, $hasher): void {
        $p = $plan();
        $response = new ModelResponse(F::invocation(), F::deployment(), F::deployment(), 'provider', FinishReason::ToolProposal,
            proposals: [new ToolProposal(F::invocation(), 'provider-p1', 'read', new JsonObject(['key' => 'value']))]);
        $saved = ExchangeResult::response($p, $response, $hasher);
        $loaded = ExchangeResult::decode($p, $saved->json, $hasher);
        H::check($loaded->proposals[0]->ordinal === 0 && $loaded->proposals[0]->actionId->value === $saved->proposals[0]->actionId->value);
        $data = json_decode($saved->json, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['normalized'] ?? null) || !is_array($data['normalized'][0] ?? null)) { throw new RuntimeException('Missing normalized fixture'); }
        foreach (['ordinal' => 3, 'action_id' => 'replacement', 'arguments_json' => '{"key":"changed"}'] as $field => $value) {
            $changed = $data; $changed['normalized'][0][$field] = $value;
            H::rejects(static fn () => ExchangeResult::decode($p, json_encode($changed, JSON_THROW_ON_ERROR), $hasher), Throwable::class);
        }
        H::rejects(static fn () => ExchangeResult::decode($p, str_replace($p->requestHash->value, 'sha-256:' . str_repeat('0', 64), $saved->json), $hasher), UnexpectedValueException::class);
    },
    'runtime.unknown-keeps-null-and-no-dispatch' => static function () use ($plan, $hasher): void {
        $unknown = ExchangeResult::unknown($plan(), $hasher);
        H::check(!$unknown->accepted() && $unknown->usage->inputTokens === null && $unknown->usage->outputTokens === null);
        H::check($unknown->proposals === [] && $unknown->finish === FinishReason::Unknown && $unknown->requestCount === null);
    },
    'runtime.unknown-admission-is-not-admitted' => static function (): void {
        $unknown = new \AgenToolsPlatform\Core\Budget\Admission('unknown');
        H::check($unknown->admissionRef === null && $unknown->reservationRef === null);
        H::rejects(static fn () => new \AgenToolsPlatform\Core\Budget\Admission('admitted'), InvalidArgumentException::class);
    },
];
H::run($cases + H::loadCases(__DIR__ . '/refusal.php'), 'unit', 'unit');
