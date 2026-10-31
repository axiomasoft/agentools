<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Common\{Certainty, ContractViolation, EffectId, ErrorCode, InvocationId, OperationReceipt, Outcome, PayloadHash, Ref, SessionId, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext, HostIdentity};
use AgenToolsPlatform\Testing\{ManualClock, OwnerLookupStep, ScriptedActionPolicy, ScriptedDisclosure, ScriptedHostAuthorization, ScriptedOwnerLookup, ScriptedRandom, ScriptedTransport};
use AgenToolsPlatform\Tests\Harness as H;

require_once __DIR__ . '/bootstrap.php';
$cases = [
 'testkit.clock-random' => static function (): void {
    $clock = new ManualClock(new UtcInstant('2026-10-31T12:00:00Z'));
    $clock->advanceSeconds(31); H::check($clock->now()->value === '2026-10-31T12:00:31Z');
    H::rejects(static fn () => $clock->advanceSeconds(-1), InvalidArgumentException::class);
    $random = new ScriptedRandom([['purpose'=>'operation-key','value'=>'saved-1']]);
    H::rejects(static fn () => $random->next('wrong-purpose'), LogicException::class);
    H::rejects(static fn () => $random->assertConsumed(), LogicException::class);
    H::check($random->next('operation-key') === 'saved-1'); $random->assertConsumed();
    H::rejects(static fn () => $random->next('operation-key'), UnderflowException::class);
 },
 'testkit.transport-boundaries' => static function (): void {
    $tenant = new TenantId('tenant-a'); $id = new InvocationId($tenant,'inv-1'); $hash = new PayloadHash('sha-256:'.str_repeat('a',64));
    $unknown = new OperationReceipt($id,$hash,Outcome::Unknown,Certainty::Unknown,null);
    $transport = new ScriptedTransport([$unknown]);
    H::rejects(static fn () => $transport->respond(new InvocationId(new TenantId('tenant-b'),'inv-1'),$hash),DomainException::class);
    H::rejects(static fn () => $transport->respond($id,new PayloadHash('sha-256:'.str_repeat('b',64))),DomainException::class);
    H::rejects(static fn () => $transport->assertConsumed(),LogicException::class);
    H::check($transport->respond($id,$hash) === $unknown);$transport->assertConsumed();
    H::rejects(static fn () => $transport->respond($id,$hash),UnderflowException::class);
    H::rejects(static fn () => new ScriptedTransport([new OperationReceipt(new EffectId($tenant,'effect'),$hash,Outcome::Unknown,Certainty::Unknown,null)]),InvalidArgumentException::class);
 },
 'testkit.owner-lookup-unknown' => static function (): void {
    $tenant = new TenantId('tenant-a');$id = new EffectId($tenant,'effect-1');$key = new Ref($tenant,'saved-owner-key');$hash = new PayloadHash('sha-256:'.str_repeat('a',64));
    $unknown = new OperationReceipt($id,$hash,Outcome::Unknown,Certainty::Unknown,null);
    $known = new OperationReceipt($id,$hash,Outcome::Succeeded,Certainty::Known,new Ref($tenant,'owner-result'));
    $lookup = new ScriptedOwnerLookup([new OwnerLookupStep($id,$key,$hash,null),new OwnerLookupStep($id,$key,$hash,$unknown),new OwnerLookupStep($id,$key,$hash,$known)]);
    H::rejects(static fn () => $lookup->lookup($id,new Ref($tenant,'replacement-key'),$hash),DomainException::class);
    H::check($lookup->lookup($id,$key,$hash) === null);
    H::check($lookup->lookup($id,$key,$hash)?->outcome === Outcome::Unknown);
    H::check($lookup->lookup($id,$key,$hash)?->outcome === Outcome::Succeeded);$lookup->assertConsumed();
    H::rejects(static fn () => $lookup->lookup($id,$key,$hash),UnderflowException::class);
 },
 'testkit.disclosure-revocation-isolation' => static function (): void {
    $host = new ScriptedHostAuthorization();$tenant = new TenantId('alpha');$session = new SessionId($tenant,'session-a');
    $host->beginJob(new HostIdentity($tenant,new Ref($tenant,'actor-a'),null,new Ref($tenant,'auth-a'),'p1'),$session);
    $context = ExecutionContext::fromHost($host,$session,'assist.read',new Correlation('trace-a','request-a'));
    $policy = new ScriptedActionPolicy();
    $policy->set($tenant,\AgenToolsPlatform\Tests\Actions\Fixtures::access(\AgenToolsPlatform\Tests\Actions\Fixtures::definition()));
    $port = new ScriptedDisclosure($host);$payload = new Ref($tenant,'private-payload');$hash = new PayloadHash('sha-256:'.str_repeat('a',64));
    H::rejects(static fn () => $port->release($context,$payload,$hash),ContractViolation::class,ErrorCode::AccessDenied);
    $port->allow($payload,$hash);H::check($port->release($context,$payload,$hash) === $payload);
    $host->revoked = true;H::rejects(static fn () => $port->release($context,$payload,$hash),ContractViolation::class,ErrorCode::AccessDenied);
    try { H::rejects(static fn () => $port->release($context,$payload,$hash),ContractViolation::class); }
    finally { $host->endJob();$port->reset(); }
    H::rejects(static fn () => $host->authorize($session,'assist.read'),ContractViolation::class);
    $beta = new TenantId('beta');$sessionB = new SessionId($beta,'session-b');
    $host->beginJob(new HostIdentity($beta,new Ref($beta,'actor-b'),null,new Ref($beta,'auth-b'),'p2'),$sessionB);
    $contextB = ExecutionContext::fromHost($host,$sessionB,'assist.read',new Correlation('trace-b','request-b'));
    H::check($policy->current($contextB)->profile === [] && $policy->current($contextB)->scopes === []);
    H::rejects(static fn () => $port->release($contextB,$payload,$hash),DomainException::class);
    H::rejects(static fn () => $host->authorize($session,'assist.read'),DomainException::class);
    H::check(!str_contains(json_encode($contextB->toArray(),JSON_THROW_ON_ERROR),'alpha'));
    $host->endJob();$policy->reset();
 },
];
if (realpath($argv[0] ?? '') === __FILE__) { H::run($cases,'contract-scripted','contract-scripted'); }
return $cases;
