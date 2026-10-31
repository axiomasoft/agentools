<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests;

use AgenTools\Schema\PublishedSchemaValidator;
use AgenTools\Schema\SchemaValidationException;
use AgenToolsPlatform\Core\Common\Certainty;
use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\InputPayload;
use AgenToolsPlatform\Core\Common\InstanceId;
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\RunCheckpoint;
use AgenToolsPlatform\Core\Common\RunId;
use AgenToolsPlatform\Core\Common\RunStatus;
use AgenToolsPlatform\Core\Common\SessionId;
use AgenToolsPlatform\Core\Common\SourceIdentity;
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Security\Correlation;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Core\Security\TrustedInput;

/** Runs real published schemas plus the explicitly declared pure semantic profile. */
final class SpecFixtures
{
    public function __construct(private PublishedSchemaValidator $validator)
    {
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException('Expected a JSON object');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException('Expected string object keys');
            }
            $result[$key] = $item;
        }
        return $result;
    }

    public static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('Expected a JSON string');
        }
        return $value;
    }

    public static function integer(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \RuntimeException('Expected a JSON integer');
        }
        return $value;
    }

    /** @return array<string, mixed> */
    public static function document(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Missing required document: ' . $path);
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Missing required document: ' . $path);
        }
        (new JcsAdapter())->canonicalizeJson($json);
        return self::object(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    private static function decodeRef(mixed $value): Ref
    {
        $data = self::object($value);
        return new Ref(new TenantId(self::string($data['tenant_id'] ?? null)), self::string($data['id'] ?? null));
    }

    /** @param array<string, mixed> $data */
    private function assertTenantScope(array $data): void
    {
        $tenants = [];
        $visit = static function (mixed $node) use (&$visit, &$tenants): void {
            if (!is_array($node)) {
                return;
            }
            foreach (['tenant_id', 'tenant_ref'] as $key) {
                if (isset($node[$key]) && is_string($node[$key])) {
                    $tenants[] = $node[$key];
                }
            }
            foreach ($node as $child) {
                $visit($child);
            }
        };
        $visit($data);
        if (count(array_unique($tenants)) > 1) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }

    /** @param array<string, mixed> $fixture */
    private function validate(array $fixture): void
    {
        $definition = self::string($fixture['definition'] ?? null);
        if (preg_match('/^[a-z_]+$/D', $definition) !== 1) {
            throw new \RuntimeException('Unexpected schema reference');
        }
        $data = self::object($fixture['data'] ?? null);
        $this->validator->assertValid($data, 'urn:agentools:platform:v1:contracts#/$defs/' . $definition);
        $this->assertTenantScope($data);
        $guards = $fixture['guards'] ?? null;
        if (!is_array($guards) || !array_is_list($guards)) {
            throw new \RuntimeException('Missing explicit semantic guard list');
        }
        $context = null;
        $host = null;
        foreach ($guards as $guard) {
            switch ($guard) {
                case 'trusted-input':
                    $ctx = self::object($data['context'] ?? null);
                    $tenant = new TenantId(self::string($ctx['tenant_id'] ?? null));
                    $actor = self::object($ctx['actor_ref'] ?? null);
                    $host = new TestHost($tenant, self::string($actor['id'] ?? null));
                    $host->revision = self::string($ctx['policy_revision'] ?? null);
                    $session = self::object($ctx['session_ref'] ?? null);
                    $correlation = self::object($ctx['correlation'] ?? null);
                    $context = ExecutionContext::fromHost($host,
                        new SessionId($tenant, self::string($session['id'] ?? null)), self::string($ctx['purpose'] ?? null),
                        new Correlation(self::string($correlation['trace_id'] ?? null), self::string($correlation['request_id'] ?? null)));
                    $src = self::object($data['source'] ?? null);
                    $instance = self::object($src['instance_ref'] ?? null);
                    $payload = self::object($data['payload'] ?? null);
                    new TrustedInput($context,
                        new SourceIdentity(new InstanceId(new TenantId(self::string($instance['tenant_id'] ?? null)), self::string($instance['id'] ?? null)),
                            self::string($src['kind'] ?? null), self::string($src['event_id'] ?? null)),
                        new InputPayload(self::decodeRef($payload['input_ref'] ?? null), new PayloadHash(self::string($payload['content_hash'] ?? null))));
                    break;
                case 'current-owner':
                    if ($host === null || $context === null) {
                        throw new \RuntimeException('Current owner guard needs trusted-input composition');
                    }
                    $host->denied = ($fixture['owner_denied'] ?? false) === true;
                    $host->revision = self::string($fixture['current_policy_revision'] ?? $host->revision);
                    $context->assertCurrent($host);
                    break;
                case 'transition':
                    $state = self::object($data['checkpoint'] ?? null);
                    $id = self::object($state['run_id'] ?? null);
                    $unresolved = $state['unresolved_effects'] ?? null;
                    if (!is_bool($unresolved)) {
                        throw new \RuntimeException('Expected unresolved effect flag');
                    }
                    $record = new RunCheckpoint(new RunId(new TenantId(self::string($id['tenant_id'] ?? null)), self::string($id['id'] ?? null)),
                        RunStatus::from(self::string($state['status'] ?? null)), self::integer($state['version'] ?? null),
                        self::integer($state['claim_epoch'] ?? null), Certainty::from(self::string($state['certainty'] ?? null)), $unresolved);
                    $record->transition(RunStatus::from(self::string($data['next_status'] ?? null)),
                        self::integer($data['expected_version'] ?? null), self::integer($data['claim_epoch'] ?? null));
                    break;
                case 'utc':
                    new UtcInstant(self::string($data['at'] ?? null));
                    break;
                default:
                    throw new \RuntimeException('Unregistered semantic guard');
            }
        }
    }

    /** @param array<string, mixed> $fixture */
    public function assertFixture(array $fixture): void
    {
        $expect = self::object($fixture['expect'] ?? null);
        if (!is_bool($expect['valid'] ?? null)) {
            throw new \RuntimeException('Expected outcome must be explicit');
        }
        $reason = null;
        try {
            $this->validate($fixture);
        } catch (SchemaValidationException|\InvalidArgumentException $error) {
            // ContractViolation extends DomainException, not InvalidArgumentException.
            $reason = ErrorCode::InvalidInput->value;
        } catch (ContractViolation $error) {
            $reason = $error->errorCode->value;
        }
        if ($expect['valid']) {
            if ($reason !== null) {
                throw new \RuntimeException('Valid fixture rejected: ' . $reason);
            }
        } elseif ($reason === null || $reason !== ($expect['reason_code'] ?? null)) {
            throw new \RuntimeException('Rejected fixture did not produce its exact reason code');
        }
    }

    /**
     * @param list<string> $required
     * @param list<string> $actual
     */
    public static function assertCaseSet(array $required, array $actual): void
    {
        if ($required === [] || $actual === [] || count(array_unique($required)) !== count($required)
            || count(array_unique($actual)) !== count($actual)) {
            throw new \RuntimeException('Empty/duplicate fixture case set');
        }
        sort($required);
        sort($actual);
        if ($required !== $actual) {
            throw new \RuntimeException('Missing/extra required fixture case');
        }
    }

    /** @return array<string, callable(): void> */
    public static function cases(string $repositoryRoot): array
    {
        $base = $repositoryRoot . '/fixtures/platform/v1/contracts';
        $manifest = self::document($base . '/manifest.json');
        $required = self::document($repositoryRoot . '/spec/platform/v1/fixture-cases.json');
        $rows = $manifest['cases'] ?? null;
        $requiredIds = $required['case_ids'] ?? null;
        if (!is_array($rows) || $rows === [] || !is_array($requiredIds) || $requiredIds === []) {
            throw new \RuntimeException('Missing/empty required fixture set');
        }
        $runner = new self(new PublishedSchemaValidator($repositoryRoot . '/spec/platform/v1/schema'));
        $cases = [];
        $seenFiles = [];
        foreach ($rows as $row) {
            $entry = self::object($row);
            $id = self::string($entry['id'] ?? null);
            $path = self::string($entry['path'] ?? null);
            if (isset($cases[$id]) || preg_match('~^(valid|rejected)/[a-z0-9-]+\.json$~D', $path) !== 1) {
                throw new \RuntimeException('Duplicate fixture ID or invalid fixture path');
            }
            $seenFiles[] = $base . '/' . $path;
            $cases[$id] = static function () use ($runner, $base, $path, $id, $entry): void {
                $fixture = self::document($base . '/' . $path);
                $expected = self::object($fixture['expect'] ?? null);
                if (($fixture['id'] ?? null) !== $id || ($expected['valid'] ?? null) !== ($entry['valid'] ?? null)
                    || ($expected['reason_code'] ?? null) !== ($entry['reason_code'] ?? null)
                    || ($entry['valid'] ?? null) !== str_starts_with($path, 'valid/')) {
                    throw new \RuntimeException('Fixture outcome differs from its declared partition');
                }
                $runner->assertFixture($fixture);
            };
        }
        $requiredStrings = [];
        foreach ($requiredIds as $id) {
            $requiredStrings[] = self::string($id);
        }
        self::assertCaseSet($requiredStrings, array_keys($cases));
        $validFiles = glob($base . '/valid/*.json');
        $rejectedFiles = glob($base . '/rejected/*.json');
        sort($seenFiles);
        if ($validFiles === false || $rejectedFiles === false) {
            throw new \RuntimeException('Cannot enumerate shared fixtures');
        }
        $files = array_merge($validFiles, $rejectedFiles);
        sort($files);
        if ($files !== $seenFiles) {
            throw new \RuntimeException('Missing/extra shared fixture cases');
        }
        return $cases;
    }
}
