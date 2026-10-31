<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Actions\{ActionAccess, ActionApplication, ActionRegistration, ActionRegistry, InputSchemaValidator};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, SessionId, TenantId};
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext, HostIdentity};
use AgenToolsPlatform\Testing\{ScriptedActionPolicy, ScriptedHostAuthorization};
use AgenToolsPlatform\Tests\{Harness as H};
use AgenToolsPlatform\Tests\Actions\{DatedLifecycle, Fixtures as F, Handler};

require_once __DIR__ . '/bootstrap.php';
$cases = [
 'testkit.real-facade-guards' => static function (): void {
    $tenant = new TenantId('tenant-a');$host = new ScriptedHostAuthorization();$session = new SessionId($tenant,'session-a');
    $host->beginJob(new HostIdentity($tenant,new Ref($tenant,'actor'),null,new Ref($tenant,'auth'),'p1'),$session);
    $context = ExecutionContext::fromHost($host,$session,'assist.read',new Correlation('trace','request'));
    $definition = F::definition();$policy = new ScriptedActionPolicy();$backend = new DatedLifecycle();
    $schemas = new class implements InputSchemaValidator {
        public function assertValid(string $schemaJson,string $inputJson): void {
            $validator = new \Opis\JsonSchema\Validator();
            $schema = json_decode($schemaJson,false,512,JSON_THROW_ON_ERROR);
            if (!$schema instanceof stdClass) { throw new ContractViolation(ErrorCode::InvalidInput); }
            $result = $validator->validate(json_decode($inputJson,false,512,JSON_THROW_ON_ERROR),$schema);
            if ($result->hasError()) { throw new ContractViolation(ErrorCode::InvalidInput); }
        }
    };
    $app = new ActionApplication(new ActionRegistry([new ActionRegistration($definition,new Handler())]),$policy,$host,$schemas,$backend);
    $submission = F::submission($definition,$context);
    H::rejects(static fn () => $app->submit($submission),ContractViolation::class,ErrorCode::AccessDenied);
    $policy->set($tenant,F::access($definition));$accepted = $app->submit($submission);
    H::check($accepted->view->receipt->wireStatus === 'created');
    H::rejects(static fn () => $app->submit(F::submission($definition,$context,'{"query":5}')),ContractViolation::class,ErrorCode::InvalidInput);
    H::rejects(static fn () => $app->submit(F::submission($definition,$context,'{"query":"changed"}')),ContractViolation::class,ErrorCode::IntegrityConflict);
    $host->revoked = true;H::rejects(static fn () => $app->submit($submission),ContractViolation::class,ErrorCode::AccessDenied);
    $host->revoked = false;$policy->reset();H::rejects(static fn () => $app->submit($submission),ContractViolation::class,ErrorCode::AccessDenied);
    H::check(count($backend->journal->events()) === 1);$host->endJob();
 },
 'testkit.real-confirmation-guards' => static function (): void {
    $port = new \AgenTools\Reference\Adapters\RegistryToolPort();
    (new \AgenTools\Reference\Tools\ReferenceSliceToolFactory(new \AgenTools\Reference\Tools\DayReportStore()))->registerOn($port,'tenant-a');
    $port->bindSubject(['initiator'=>['id'=>'user-a'],'agent'=>['id'=>'agent-a'],'authorizer'=>['id'=>'user-a']],
        ['resource'=>'day-report','tool'=>'slice_write_day_report','permissions'=>['slice.write']]);
    $clock = new class implements \AgenTools\Contracts\Clock {
        public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-31T12:00:00Z'); }
    };
    $service = (new \AgenTools\Reference\Scenario\ReferenceConfirmationServiceFactory())->create($port,$clock,'tenant-a','user-a',['slice.write']);
    $args = ['report_id'=>'report-1','date'=>'2026-10-31','body'=>'sensitive-test-text'];
    $token = $service->prepare('act-1','slice_write_day_report',$args,['summary'=>'test','changes'=>[]]);
    H::rejects(static function () use ($service,$token,$args): void {
        try { $service->commit($token->value,$args+['injected'=>['secret'=>['token'=>'sensitive-nested-test-token']]]); }
        catch (\AgenTools\Confirmation\ConfirmationFailure $failure) {
            $diagnostic = json_encode($failure->error->toArray(),JSON_THROW_ON_ERROR) . $failure->getMessage();
            H::check(!str_contains($diagnostic,'sensitive-test-text') && !str_contains($diagnostic,'sensitive-nested-test-token'));
            throw $failure;
        }
    },\AgenTools\Confirmation\ConfirmationFailure::class);
    H::check($service->commit($token->value,$args)->value === $token->value);
    H::rejects(static fn () => $service->commit($token->value,$args),\AgenTools\Confirmation\ConfirmationFailure::class);
 },
];
if (realpath($argv[0] ?? '') === __FILE__) { H::run($cases,'contract-scripted','contract-scripted'); }
return $cases;
