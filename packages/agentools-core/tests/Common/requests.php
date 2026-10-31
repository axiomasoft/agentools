<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Actions\ActionRequest;
use AgenToolsPlatform\Core\Common\ActionId;
use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Core\Common\DeliveryId;
use AgenToolsPlatform\Core\Common\DeliveryIntent;
use AgenToolsPlatform\Core\Common\InvocationId;
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\ProposalBinding;
use AgenToolsPlatform\Core\Common\ProposalId;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\RunId;
use AgenToolsPlatform\Core\Common\SessionId;
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Models\InvocationIdentity;
use AgenToolsPlatform\Core\Profiles\DefinitionRef;
use AgenToolsPlatform\Core\Security\Correlation;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\JcsAdapter;
use AgenToolsPlatform\Tests\SpecFixtures;
use AgenToolsPlatform\Tests\TestHost;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/SpecFixtures.php';
$tenant = new TenantId('tenant-a');
$hasher = new CanonicalHasher(new JcsAdapter());
$hash = new PayloadHash('sha-256:' . str_repeat('a', 64));
$deadline = new UtcInstant('2026-10-31T12:00:00Z');
$context = ExecutionContext::fromHost(new TestHost($tenant), new SessionId($tenant, 'session-1'), 'assist.read', new Correlation('trace-1', 'request-1'));
$definition = new DefinitionRef('record.read', '1.0', $hash);
$cases = [
    'request.direct-actions' => static function () use ($tenant, $hasher, $hash, $deadline, $context, $definition): void {
        $request = new ActionRequest($definition, new Ref($tenant, 'direct-origin'), null, new Ref($tenant, 'direct-key'), new Ref($tenant, 'input'), $hash, $context, $deadline, $hasher);
        H::check($request->toArray()['proposal_id'] === null && $request->scopeHash->value === $context->scopeHash($hasher)->value);
        $fixture = SpecFixtures::document(dirname(__DIR__, 4) . '/fixtures/platform/v1/contracts/valid/35-direct-action-request.json');
        $jcs = new JcsAdapter();
        H::check($jcs->canonicalize($request->toArray()) === $jcs->canonicalize($fixture['data'] ?? null));
    },
    'request.stable-hash' => static function () use ($tenant, $hasher, $hash, $deadline, $context, $definition): void {
        $make = static fn (ExecutionContext $ctx, PayloadHash $input): ActionRequest => new ActionRequest($definition, new Ref($tenant, 'origin'), null, new Ref($tenant, 'key'), new Ref($tenant, 'input'), $input, $ctx, $deadline, $hasher);
        $one = $make($context, $hash);
        $retry = ExecutionContext::fromHost(new TestHost($tenant), $context->session, $context->purpose, new Correlation('trace-2', 'request-2'));
        H::check($one->requestHash->value === $make($retry, $hash)->requestHash->value);
        H::rejects(static fn () => $one->requestHash->assertSame($make($retry, $hasher->hash($tenant, 'input', 'changed'))->requestHash), DomainException::class);
    },
    'request.tenant-denied' => static function () use ($tenant, $hasher, $hash, $deadline, $context, $definition): void {
        H::rejects(static fn () => new ActionRequest($definition, new Ref(new TenantId('tenant-b'), 'origin'), null, new Ref($tenant, 'key'), new Ref($tenant, 'input'), $hash, $context, $deadline, $hasher), DomainException::class);
    },
    'request.registry-not-class-loader' => static function () use ($hash): void {
        foreach (['App\\ArbitraryHandler', 'https://example.test/effect', 'SELECT * FROM users'] as $id) {
            H::rejects(static fn () => new DefinitionRef($id, '1', $hash), InvalidArgumentException::class);
        }
    },
    'proposal.saved-confirmation-mapping' => static function () use ($tenant, $hash): void {
        $one = new ProposalBinding(new ProposalId($tenant, 'proposal-1'), new ActionId($tenant, 'dated-action-1'), $hash, new Ref($tenant, 'saved-confirmation-1'));
        $one->assertReplay($one);
        H::check($one->toArray()['confirmation_ref'] === $one->confirmationRef?->toArray());
        H::rejects(static fn () => $one->assertReplay(new ProposalBinding($one->proposalId, new ActionId($tenant, 'new-action'), $hash, $one->confirmationRef)), DomainException::class);
        H::rejects(static fn () => $one->assertReplay(new ProposalBinding($one->proposalId, $one->actionId, $hash, null)), DomainException::class);
    },
    'delivery.saved-response-binding' => static function () use ($tenant, $hash): void {
        $one = new DeliveryIntent(new DeliveryId($tenant, 'delivery-1'), new RunId($tenant, 'run-1'), 1, new Ref($tenant, 'destination'), new Ref($tenant, 'response'), $hash);
        $one->assertReplay($one);
        H::rejects(static fn () => $one->assertReplay(new DeliveryIntent($one->id, $one->runId, 2, $one->destination, $one->responseRef, $hash)), DomainException::class);
    },
    'invocation.fallback-new-identity' => static function () use ($tenant, $hash, $deadline): void {
        $first = new InvocationId($tenant, 'invocation-1');
        $next = new InvocationIdentity(new InvocationId($tenant, 'invocation-2'), new RunId($tenant, 'run-1'), $first, new Ref($tenant, 'request'), $hash, $deadline);
        H::check($next->toArray()['parent_invocation_id'] === $first->toArray());
        H::rejects(static fn () => new InvocationIdentity($first, $next->runId, $first, $next->requestRef, $hash, $deadline), InvalidArgumentException::class);
    },
];
if (realpath($argv[0] ?? '') === __FILE__) {
    H::run($cases);
}
return $cases;
