<?php

declare(strict_types=1);

namespace AgenTools\Reference\Adapters;

use AgenTools\Contracts\ToolPort;
use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Ops\PrincipalTriple;
use AgenTools\Tool\ToolDescriptor;

/** Single in-process tool registry with discover and invoke permission gates. */
final class RegistryToolPort implements ToolPort
{
    /** @var array<string, array{descriptor: ToolDescriptor, handler: callable}> */
    private array $tools = [];

    /** @var array<string, mixed>|null */
    private ?array $principals = null;

    /** @var array<string, mixed>|null */
    private ?array $authorization = null;

    public function __construct(
        private readonly PrincipalTriple $principalTriple = new PrincipalTriple(),
    ) {
    }

    public function register(ToolDescriptor $descriptor, callable $handler): void
    {
        $this->tools[$descriptor->name] = [
            'descriptor' => $descriptor,
            'handler' => $handler,
        ];
    }

    /**
     * @param array<string, mixed> $principals
     * @param array<string, mixed> $authorization
     */
    public function bindSubject(array $principals, array $authorization): void
    {
        $failure = $this->principalTriple->validate($principals, $authorization);
        if ($failure !== null) {
            throw new \InvalidArgumentException(sprintf('Principal triple is invalid: %s.', $failure));
        }

        $this->principals = $principals;
        $this->authorization = $authorization;
    }

    /** @return list<ToolDescriptor> */
    public function discover(): array
    {
        $this->assertSubjectBound();

        $visible = [];
        foreach ($this->tools as $entry) {
            if ($this->isPermitted($entry['descriptor'])) {
                $visible[] = $entry['descriptor'];
            }
        }

        return $visible;
    }

    /** @param array<string, mixed> $arguments */
    public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
    {
        if ($this->principals === null || $this->authorization === null) {
            return ToolExecutionResult::failure(new ToolError(
                'agentools.permission.missing_subject',
                ErrorCategory::PERMISSION,
                false,
                'A subject principal triple is required before tool invocation.',
            ));
        }

        $failure = $this->principalTriple->validate($this->principals, $this->authorization);
        if ($failure !== null) {
            return ToolExecutionResult::failure(new ToolError(
                'agentools.permission.invalid_subject',
                ErrorCategory::PERMISSION,
                false,
                'The bound subject principal triple is invalid.',
                sprintf('Validation failed with %s.', $failure),
            ));
        }

        $entry = $this->tools[$tool] ?? null;
        if ($entry === null) {
            return ToolExecutionResult::failure(new ToolError(
                'agentools.not_found.tool',
                ErrorCategory::NOT_FOUND,
                false,
                'The requested tool is not registered.',
            ));
        }

        if (!$this->isPermitted($entry['descriptor'])) {
            return ToolExecutionResult::failure(new ToolError(
                'agentools.permission.tool_not_allowed',
                ErrorCategory::PERMISSION,
                false,
                'The subject is not allowed to invoke this tool.',
            ));
        }

        $handler = $entry['handler'];
        /** @var ToolExecutionResult $result */
        $result = $handler($arguments, $idempotencyKey, $this->principals, $this->authorization);

        return $result;
    }

    private function assertSubjectBound(): void
    {
        if ($this->principals === null || $this->authorization === null) {
            throw new \InvalidArgumentException('A subject principal triple is required before tool discovery.');
        }
    }

    private function isPermitted(ToolDescriptor $descriptor): bool
    {
        $required = $descriptor->actionProfile->permissionsHint ?? [];
        if ($required === []) {
            return true;
        }

        $granted = $this->authorization['permissions'] ?? null;
        if (!is_array($granted)) {
            return false;
        }

        foreach ($required as $permission) {
            if (!in_array($permission, $granted, true)) {
                return false;
            }
        }

        return true;
    }
}
