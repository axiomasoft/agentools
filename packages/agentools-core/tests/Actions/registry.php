<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Actions\{ActionAccess, ActionDefinition, ActionRegistration, ActionRegistry};
use AgenToolsPlatform\Core\Common\{ContractViolation, PayloadHash};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;
use AgenToolsPlatform\Tests\{Harness as H, JcsAdapter};
use AgenToolsPlatform\Tests\Actions\{Fixtures as F, Handler};

require_once __DIR__ . '/Fixtures.php';
$cases = [
    'V2ALL-CATALOG-POSITIVE' => static function (): void {
        $definition = F::definition();
        $registry = new ActionRegistry([new ActionRegistration($definition, new Handler())]);
        $access = F::access($definition);
        $execution = $registry->executionView($definition->ref, $access);
        $model = $registry->modelView($access);
        $admin = $registry->adminView($access);
        $json = new JcsAdapter();
        H::check(count($model) === 1 && count($admin) === 1 && $execution->definition === $definition);
        H::check($json->canonicalize($model[0]['input_schema']) === $definition->inputSchema);
        H::check($json->canonicalize($admin[0]['input_schema']) === $definition->inputSchema);
        H::check($model[0]['definition_ref'] === $definition->ref->toArray() && !array_key_exists('handler', $admin[0]));
        H::check($admin[0]['cost_bound_minor'] === null && $admin[0]['max_attempts'] === 1);
        $model[0]['input_schema'] = 'mutated';
        H::check($registry->executionView($definition->ref, $access)->definition->inputSchema === $definition->inputSchema);
        $mutatedMetadata = $definition->metadata();
        if ($mutatedMetadata['input_schema'] instanceof stdClass) { $mutatedMetadata['input_schema']->type = 'string'; }
        H::check($definition->inputSchema === F::definition()->inputSchema);
        H::check((new ReflectionClass(ActionDefinition::class))->isReadOnly());
    },
    'V2ALL-CATALOG-NEGATIVE' => static function (): void {
        $definition = F::definition();
        $access = F::access($definition);
        $registry = new ActionRegistry([new ActionRegistration($definition, new Handler())]);
        foreach (['App\\InjectedHandler', 'SELECT * FROM secrets', 'https://example.test/effect'] as $injection) {
            H::rejects(static fn () => new DefinitionRef($injection, '1', $definition->ref->hash), InvalidArgumentException::class);
        }
        H::rejects(static fn () => $registry->executionView(new DefinitionRef('unregistered.tool', '1', $definition->ref->hash), $access), ContractViolation::class);
        foreach (range(0, 5) as $removed) {
            $sets = [$access->profile, $access->skill, $access->host, $access->session, $access->available, $access->scopes];
            $sets[$removed] = [];
            $restricted = new ActionAccess(...$sets);
            H::check($registry->modelView($restricted) === [] && $registry->adminView($restricted) === []);
            H::rejects(static fn () => $registry->executionView($definition->ref, $restricted), ContractViolation::class);
        }
        H::rejects(static fn () => new ActionRegistry([new ActionRegistration($definition, new Handler()), new ActionRegistration(F::definition(input: '{"type":"string"}'), new Handler())]), ContractViolation::class);
    },
    'V2ALL-CATALOG-FAULT' => static function (): void {
        $one = F::definition(); $two = F::definition('2');
        $registry = new ActionRegistry([new ActionRegistration($one, new Handler()), new ActionRegistration($two, new Handler())]);
        H::check($registry->executionView($one->ref, F::access($one))->definition === $one);
        H::check($one->ref->hash->value !== $two->ref->hash->value);
        H::rejects(static fn () => $registry->executionView(new DefinitionRef($one->ref->id, '1', $two->ref->hash), F::access($one)), DomainException::class);
        $revoked = F::access($two);
        H::rejects(static fn () => $registry->executionView($one->ref, $revoked), ContractViolation::class);
        H::rejects(static fn () => (new ActionRegistry([new ActionRegistration($two, new Handler())]))->executionView($one->ref, F::access($one)), ContractViolation::class);
    },
    'registry.hash-content-and-policy' => static function (): void {
        $one = F::definition();
        H::check($one->ref->hash->value === F::definition()->ref->hash->value);
        $different = F::definition(input: '{"type":"object","properties":{"query":{"type":"number"}}}');
        H::check($one->ref->hash->value !== $different->ref->hash->value);
        H::check($one->ref->hash->value !== F::definition(requiredScopes: ['records.write'])->ref->hash->value);
        H::rejects(static fn () => F::definition(requiredScopes: ['records.read', 'records.read']), InvalidArgumentException::class);
        H::rejects(static fn () => F::definition(input: '[]'), InvalidArgumentException::class);
        H::rejects(static fn () => F::definition(input: '{"type":"object","type":"string"}'), InvalidArgumentException::class);
        $wrong = new class implements \AgenToolsPlatform\Core\Actions\ActionHandler {
            public function handlerId(): string { return 'injected.owner'; }
        };
        H::rejects(static fn () => new ActionRegistration($one, $wrong), InvalidArgumentException::class);
    },
];
if (realpath($argv[0] ?? '') === __FILE__) { H::run($cases, 'unit', 'unit'); }
return $cases;
