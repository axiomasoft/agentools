<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Common\AcceptedTurn;
use AgenToolsPlatform\Core\Common\ActionId;
use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\EffectId;
use AgenToolsPlatform\Core\Common\EffectRequest;
use AgenToolsPlatform\Core\Common\IdentityKeys;
use AgenToolsPlatform\Core\Common\InputPayload;
use AgenToolsPlatform\Core\Common\InstanceId;
use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\SourceIdentity;
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\JcsAdapter;

require_once __DIR__ . '/bootstrap.php';

$tenant = new TenantId('tenant-a');
$hash = new CanonicalHasher(new JcsAdapter());
$keys = new IdentityKeys($hash);
$source = new SourceIdentity(new InstanceId($tenant, 'instance-1'), 'host.message', 'event-1');
$payload = new InputPayload(new Ref($tenant, 'input-1'), $hash->hash($tenant, 'input-bytes', 'hello'));
$turn = $keys->turn($source);
$run = $keys->run($turn);
$invocation = $keys->invocation($run, 'step-1', 1);
$cases = [
    'identity.dated-opaque-preserved' => static function () use ($tenant): void {
        $value = 'legacy action#1/été:' . str_repeat('x', 300);
        H::check((new ActionId($tenant, $value))->value === $value);
        H::check((new EffectId($tenant, $value))->value === $value);
        $validator = new \AgenTools\Schema\PublishedSchemaValidator(dirname(__DIR__, 4) . '/spec/platform/v1/schema');
        $receipt = new \AgenToolsPlatform\Core\Common\ActionReceipt(new ActionId($tenant, $value), 'created', 1);
        $validator->assertValid($receipt->toArray(), 'urn:agentools:platform:v1:contracts#/$defs/action_receipt');
        H::rejects(static fn () => new ActionId($tenant, ''), InvalidArgumentException::class);
        H::rejects(static fn () => new EffectId($tenant, ''), InvalidArgumentException::class);
    },
    'identity.replay' => static function () use ($source, $payload, $hash, $turn, $run): void {
        $receipt = new AcceptedTurn($source->key($hash), $source->payloadHash($payload, $hash), $turn, $run);
        H::check($receipt->replay($source, $source->payloadHash($payload, $hash), $hash) === $receipt);
        H::check($receipt->toArray()['run_id'] === $run->toArray());
    },
    'identity.hash-conflict' => static function () use ($source, $payload, $hash, $turn, $run, $tenant): void {
        $receipt = new AcceptedTurn($source->key($hash), $source->payloadHash($payload, $hash), $turn, $run);
        H::rejects(static fn () => $receipt->replay($source, $hash->hash($tenant, 'source-payload', 'changed'), $hash), IntegrityConflict::class);
        H::check($run->value === (new IdentityKeys($hash))->run($turn)->value);
    },
    'identity.tenant-instance-scope' => static function () use ($keys, $source, $turn): void {
        foreach ([new InstanceId(new TenantId('tenant-b'), 'instance-1'), new InstanceId($source->instance->tenant, 'instance-2')] as $instance) {
            H::check($keys->turn(new SourceIdentity($instance, $source->kind, $source->eventId))->value !== $turn->value);
        }
    },
    'identity.cross-tenant-rejected' => static function () use ($source, $hash): void {
        $b = new TenantId('tenant-b');
        H::rejects(static fn () => $source->payloadHash(new InputPayload(new Ref($b, 'input-1'), new PayloadHash('sha-256:' . str_repeat('a', 64))), $hash), ContractViolation::class);
    },
    'identity.invocation-attempt' => static function () use ($keys, $run, $invocation): void {
        H::check($keys->invocation($run, 'step-1', 1)->value === $invocation->value);
        H::check($keys->invocation($run, 'step-1', 2)->value !== $invocation->value);
        H::rejects(static fn () => $keys->invocation($run, 'step-1', 0), InvalidArgumentException::class);
    },
    'identity.proposal-saved-key' => static function () use ($keys, $invocation): void {
        H::check($keys->proposal($invocation, 'provider-1', null)->value === $keys->proposal($invocation, 'provider-1', null)->value);
        H::check($keys->proposal($invocation, null, 0)->value !== $keys->proposal($invocation, null, 1)->value);
        H::rejects(static fn () => $keys->proposal($invocation, null, null), InvalidArgumentException::class);
        H::rejects(static fn () => $keys->proposal($invocation, 'provider-1', 0), InvalidArgumentException::class);
    },
    'identity.effect-owner-conflict' => static function () use ($tenant, $keys, $hash): void {
        $action = new ActionId($tenant, '018f6f32-09a2-7b45-8000-000000000001');
        $request = new EffectRequest($action, new EffectId($tenant, 'dated-effect-1'), $keys->effectKey($action, 0), new Ref($tenant, 'owner-operation-1'), $hash->hash($tenant, 'request', ['x' => 1]));
        $request->assertReplay($request);
        $other = new EffectRequest($action, $request->effectId, $request->effectKey, $request->ownerOperationRef, $hash->hash($tenant, 'request', ['x' => 2]));
        H::rejects(static fn () => $request->assertReplay($other), IntegrityConflict::class);
        H::check($action->value === '018f6f32-09a2-7b45-8000-000000000001');
    },
    'identity.delivery-version-destination' => static function () use ($tenant, $keys, $run): void {
        $one = $keys->delivery($run, 1, new Ref($tenant, 'destination-1'));
        H::check($one->value !== $keys->delivery($run, 2, new Ref($tenant, 'destination-1'))->value);
        H::check($one->value !== $keys->delivery($run, 1, new Ref($tenant, 'destination-2'))->value);
        H::rejects(static fn () => $keys->delivery($run, 1, new Ref(new TenantId('tenant-b'), 'destination-1')), ContractViolation::class);
    },
    'identity.usage-version' => static function () use ($keys, $invocation): void {
        H::check($keys->usage($invocation, 1)->value !== $keys->usage($invocation, 2)->value);
        H::rejects(static fn () => $keys->usage($invocation, 0), InvalidArgumentException::class);
    },
    'identity.occurrence-utc' => static function () use ($keys, $tenant): void {
        $schedule = new Ref($tenant, 'schedule-1');
        H::check($keys->occurrence($schedule, new UtcInstant('2026-10-31T12:00:00.000Z'))->value === $keys->occurrence($schedule, new UtcInstant('2026-10-31T12:00:00Z'))->value);
        foreach (['2026-02-30T12:00:00Z', '2026-10-31T12:00:00+00:00', '2026-10-31T24:00:00Z', 'now'] as $bad) {
            H::rejects(static fn () => new UtcInstant($bad), InvalidArgumentException::class);
        }
    },
    'identity.invalid-id-hash' => static function (): void {
        H::rejects(static fn () => new TenantId(''), InvalidArgumentException::class);
        H::rejects(static fn () => new TenantId('global'), InvalidArgumentException::class);
        H::rejects(static fn () => new Ref(new TenantId('tenant-a'), 'raw text with spaces'), InvalidArgumentException::class);
        H::rejects(static fn () => new PayloadHash('sha-256:not-a-hash'), InvalidArgumentException::class);
    },
];
if (realpath($argv[0] ?? '') === __FILE__) {
    H::run($cases);
}
return $cases;
