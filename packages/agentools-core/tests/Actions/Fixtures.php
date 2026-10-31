<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Actions;

use AgenToolsPlatform\Core\Actions\{ActionAccess, ActionDefinition, ActionHandler, ActionPolicy, EffectClass, InputSchemaValidator};
use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, Ref, SessionId, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Actions\{ActionRequest, ActionSubmission};
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext};
use AgenToolsPlatform\Tests\{JcsAdapter, TestHost};

require_once dirname(__DIR__) . '/Common/bootstrap.php';

final class Fixtures
{
    /** @param list<string> $requiredScopes */
    public static function definition(string $version = '1', string $input = '{"type":"object","required":["query"],"properties":{"query":{"type":"string"}},"additionalProperties":false}', array $requiredScopes = ['records.read']): ActionDefinition
    {
        return new ActionDefinition('record.read', $version, 'records.owner', $input, '{"type":"object"}',
            EffectClass::Read, $requiredScopes, false, 1000, 1, 0, 3000, 'native_key', null,
            'tenant-region', 'technical-ref-only', 'omit-payload', ['definition', 'actor', 'outcome'], null, new JcsAdapter());
    }

    public static function access(ActionDefinition $definition): ActionAccess
    {
        $keys = [ActionAccess::key($definition->ref)];
        return new ActionAccess($keys, $keys, $keys, $keys, $keys, ['records.read']);
    }

    public static function context(TestHost $host): ExecutionContext
    {
        return ExecutionContext::fromHost($host, new SessionId($host->tenant, 'session-1'), 'assist.read', new Correlation('trace-1', 'request-1'));
    }

    public static function submission(ActionDefinition $definition, ExecutionContext $context, string $input = '{"query":"hello"}', string $key = 'submit-1'): ActionSubmission
    {
        $tenant = $context->identity->tenant;
        $hasher = new CanonicalHasher(new JcsAdapter());
        return new ActionSubmission(new ActionRequest($definition->ref, new Ref($tenant, 'direct'), null,
            new Ref($tenant, $key), new Ref($tenant, 'input'),
            $hasher->hash($tenant, 'action-input', json_decode($input, false, 512, JSON_THROW_ON_ERROR)), $context,
            new UtcInstant('2026-11-01T00:00:00Z'), $hasher), $input, $hasher, new JcsAdapter());
    }
}

final class Handler implements ActionHandler
{
    public function handlerId(): string { return 'records.owner'; }
}

final class Policy implements ActionPolicy
{
    public int $calls = 0;
    public function __construct(public ActionAccess $access) {}
    public function current(ExecutionContext $context): ActionAccess { ++$this->calls; return $this->access; }
}

/** Local scripted schema port checks this test's actual input contract, not a production validator. */
final class Schema implements InputSchemaValidator
{
    /** @var list<string> */
    public array $seen = [];
    public function assertValid(string $schemaJson, string $inputJson): void
    {
        $this->seen[] = $schemaJson;
        $value = json_decode($inputJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_keys($value) !== ['query'] || !is_string($value['query'])) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
    }
}
