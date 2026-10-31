<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\SessionId;
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Security\Correlation;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Core\Security\HostIdentity;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\JcsAdapter;
use AgenToolsPlatform\Tests\TestHost;

require_once __DIR__ . '/bootstrap.php';

$a = new TenantId('tenant-a');
$b = new TenantId('tenant-b');
$session = new SessionId($a, 'session-1');
$trace = new Correlation('trace-1', 'request-1');
$cases = [
    'context.trusted-factory' => static function () use ($a, $session, $trace): void {
        $host = new TestHost($a);
        $context = ExecutionContext::fromHost($host, $session, 'assist.read', $trace);
        H::check($host->calls === 1 && $context->identity->actorRef->value === 'actor-a');
        $context->assertCurrent($host);
        H::check($host->calls === 2);
    },
    'context.browser-flags-rejected' => static function () use ($session, $trace): void {
        $factory = new ReflectionMethod(ExecutionContext::class, 'fromHost');
        H::rejects(static fn () => $factory->invokeArgs(null, [['authorized' => true], $session, 'assist.read', $trace]), TypeError::class);
        H::check(!(new ReflectionClass(ExecutionContext::class))->getConstructor()?->isPublic());
        H::check(!(new ReflectionClass(ExecutionContext::class))->hasMethod('fromArray'));
    },
    'context.tenant-mismatch' => static function () use ($b, $session, $trace): void {
        H::rejects(static fn () => ExecutionContext::fromHost(new TestHost($b), $session, 'assist.read', $trace), ContractViolation::class, ErrorCode::AccessDenied);
    },
    'context.revoke-overrides-pin' => static function () use ($a, $session, $trace): void {
        $host = new TestHost($a);
        $context = ExecutionContext::fromHost($host, $session, 'assist.read', $trace);
        $host->denied = true;
        H::rejects(static fn () => $context->assertCurrent($host), ContractViolation::class, ErrorCode::AccessDenied);
    },
    'context.policy-refresh' => static function () use ($a, $session, $trace): void {
        $host = new TestHost($a);
        $context = ExecutionContext::fromHost($host, $session, 'assist.read', $trace);
        $host->revision = 'policy-2';
        H::rejects(static fn () => $context->assertCurrent($host), ContractViolation::class, ErrorCode::StaleContext);
    },
    'context.actor-refresh' => static function () use ($a, $session, $trace): void {
        $host = new TestHost($a);
        $context = ExecutionContext::fromHost($host, $session, 'assist.read', $trace);
        $host->actor = 'actor-b';
        H::rejects(static fn () => $context->assertCurrent($host), ContractViolation::class, ErrorCode::StaleContext);
    },
    'context.scope-binding' => static function () use ($a, $session, $trace): void {
        $host = new TestHost($a);
        $hasher = new CanonicalHasher(new JcsAdapter());
        $context = ExecutionContext::fromHost($host, $session, 'assist.read', $trace);
        $snapshot = new ContextSnapshot(new Ref($a, 'snapshot-1'), 1, $hasher->hash($a, 'snapshot', ['refs' => []]), $context->scopeHash($hasher));
        $snapshot->assertScope($context, $hasher);
        $changed = ExecutionContext::fromHost($host, $session, 'assist.write', $trace);
        H::rejects(static fn () => $snapshot->assertScope($changed, $hasher), DomainException::class);
        $otherTrace = ExecutionContext::fromHost($host, $session, 'assist.read', new Correlation('trace-2', 'request-2'));
        H::check($context->scopeHash($hasher)->value === $otherTrace->scopeHash($hasher)->value);
    },
    'context.worker-tenant-switch' => static function () use ($a, $b, $session, $trace): void {
        $host = new TestHost($a);
        $first = ExecutionContext::fromHost($host, $session, 'assist.read', $trace);
        $host->tenant = $b;
        $host->actor = 'actor-b';
        $second = ExecutionContext::fromHost($host, new SessionId($b, 'session-2'), 'assist.read', $trace);
        H::check($first->identity->tenant->value === 'tenant-a' && $second->identity->tenant->value === 'tenant-b');
        H::rejects(static fn () => $first->assertCurrent($host), ContractViolation::class, ErrorCode::AccessDenied);
    },
    'context.immutable' => static function () use ($a, $session, $trace): void {
        $context = ExecutionContext::fromHost(new TestHost($a), $session, 'assist.read', $trace);
        H::check((new ReflectionClass($context))->isReadOnly());
        H::check((new ReflectionClass($context->identity))->isReadOnly());
        H::rejects(static fn () => new HostIdentity($a, new Ref(new TenantId('tenant-b'), 'actor'), null, new Ref($a, 'auth'), 'rev'), ContractViolation::class);
        H::rejects(static fn () => new HostIdentity($a, new Ref($a, 'actor'), null, new Ref($a, 'auth'), ' '), InvalidArgumentException::class);
    },
];
if (realpath($argv[0] ?? '') === __FILE__) {
    H::run($cases);
}
return $cases;
