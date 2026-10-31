<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Common\ActionId;
use AgenToolsPlatform\Core\Common\ActionReceipt;
use AgenToolsPlatform\Core\Common\Certainty;
use AgenToolsPlatform\Core\Common\InvocationId;
use AgenToolsPlatform\Core\Common\OperationReceipt;
use AgenToolsPlatform\Core\Common\Outcome;
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Core\Common\UsageVersion;
use AgenToolsPlatform\Core\Common\UsageVersionId;
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Tests\Harness as H;

require_once __DIR__ . '/bootstrap.php';
$tenant = new TenantId('tenant-a');
$hash = new PayloadHash('sha-256:' . str_repeat('a', 64));
$invocation = new InvocationId($tenant, 'invocation-1');
$usage = new UsageVersionId($tenant, 'usage-1');
$cases = [
    'receipt.unknown-distinct' => static function () use ($invocation, $hash): void {
        $unknown = new OperationReceipt($invocation, $hash, Outcome::Unknown, Certainty::Unknown, null);
        H::check($unknown->toArray()['outcome'] === 'unknown' && $unknown->toArray()['result_ref'] === null);
        H::rejects(static fn () => new OperationReceipt($invocation, $hash, Outcome::Failed, Certainty::Unknown, null), InvalidArgumentException::class);
        H::rejects(static fn () => new OperationReceipt($invocation, $hash, Outcome::Succeeded, Certainty::Known, null), InvalidArgumentException::class);
    },
    'receipt.known-observation' => static function () use ($invocation, $hash, $tenant): void {
        foreach ([Outcome::Succeeded, Outcome::Failed] as $outcome) {
            H::check((new OperationReceipt($invocation, $hash, $outcome, Certainty::Known, new Ref($tenant, 'result-1')))->outcome === $outcome);
        }
    },
    'receipt.cross-tenant' => static function () use ($invocation, $hash): void {
        H::rejects(static fn () => new OperationReceipt($invocation, $hash, Outcome::Succeeded, Certainty::Known, new Ref(new TenantId('tenant-b'), 'result')), DomainException::class);
    },
    'usage.null-versus-zero' => static function () use ($usage, $invocation, $hash): void {
        $unknown = new UsageVersion($usage, $invocation, 1, $hash, null, null, Certainty::Unknown);
        $partial = new UsageVersion($usage, $invocation, 1, $hash, 7, null, Certainty::Unknown);
        $zero = new UsageVersion($usage, $invocation, 1, $hash, 0, 0, Certainty::Known);
        H::check($unknown->toArray()['input_tokens'] === null && $partial->toArray()['input_tokens'] === 7 && $zero->toArray()['input_tokens'] === 0);
        H::rejects(static fn () => new UsageVersion($usage, $invocation, 1, $hash, null, null, Certainty::Known), InvalidArgumentException::class);
        H::rejects(static fn () => new UsageVersion($usage, $invocation, 1, $hash, -1, 0, Certainty::Known), InvalidArgumentException::class);
    },
    'usage.same-version-conflict' => static function () use ($usage, $invocation, $hash): void {
        $one = new UsageVersion($usage, $invocation, 1, $hash, 1, 2, Certainty::Known);
        $one->assertReplay($one);
        H::rejects(static fn () => $one->assertReplay(new UsageVersion($usage, $invocation, 1, $hash, 1, 3, Certainty::Known)), DomainException::class);
    },
    'profile.immutable-pin' => static function () use ($tenant, $hash): void {
        $pin = new BundleRef(new Ref($tenant, 'bundle-1'), 1, $hash);
        H::check((new ReflectionClass($pin))->isReadOnly() && $pin->toArray()['hash'] === $hash->value);
        H::rejects(static fn () => new BundleRef($pin->ref, 0, $hash), InvalidArgumentException::class);
    },
    'receipt.no-new-wire-status' => static function () use ($tenant): void {
        H::rejects(static fn () => new ActionReceipt(new ActionId($tenant, 'action-1'), 'effect_unknown', 1), InvalidArgumentException::class);
        H::rejects(static fn () => new ActionReceipt(new ActionId($tenant, 'action-1'), 'completed', 0), InvalidArgumentException::class);
    },
];
if (realpath($argv[0] ?? '') === __FILE__) {
    H::run($cases);
}
return $cases;
