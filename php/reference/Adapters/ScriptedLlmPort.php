<?php

declare(strict_types=1);

namespace AgenTools\Reference\Adapters;

use AgenTools\Contracts\LlmPort;
use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Error\ToolExecutionException;
use AgenTools\Error\ToolExecutionResult;

/** Scripted LLM boundary without network calls or provider gateways. */
final class ScriptedLlmPort implements LlmPort
{
    public const QUOTA_STRUCTURAL_CODE = 'LLM_QUOTA_EXCEEDED';

    public function __construct(
        private readonly ?string $fixturesDir = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function respond(array $input): array
    {
        if ($this->isQuotaExceeded($input)) {
            throw new ToolExecutionException($this->quotaError());
        }

        if (isset($input['llm_stub']) && is_array($input['llm_stub'])) {
            /** @var array<string, mixed> $stub */
            $stub = $input['llm_stub'];

            return $stub;
        }

        if (isset($input['fixture']) && is_string($input['fixture'])) {
            return $this->loadFixture($input['fixture']);
        }

        return [];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function respondAsToolResult(array $input): ToolExecutionResult
    {
        try {
            return ToolExecutionResult::success($this->respond($input));
        } catch (ToolExecutionException $exception) {
            return ToolExecutionResult::failure($exception->error);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function isQuotaExceeded(array $input): bool
    {
        if (($input['error_code'] ?? null) === self::QUOTA_STRUCTURAL_CODE) {
            return true;
        }

        $stub = $input['llm_stub'] ?? null;
        if (!is_array($stub)) {
            return false;
        }

        return ($stub['error'] ?? null) === self::QUOTA_STRUCTURAL_CODE
            || ($stub['error_code'] ?? null) === self::QUOTA_STRUCTURAL_CODE;
    }

    private function quotaError(): ToolError
    {
        return new ToolError(
            ToolErrorCode::LimitExceeded->value,
            ErrorCategory::BUDGET,
            false,
            'LLM quota exceeded.',
            'The scripted provider reported LLM_QUOTA_EXCEEDED.',
            ['structural_code' => self::QUOTA_STRUCTURAL_CODE],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $path = rtrim($this->fixturesDirectory(), '/\\') . DIRECTORY_SEPARATOR . $name . '.json';
        if (!is_file($path)) {
            throw new \InvalidArgumentException(sprintf('LLM fixture "%s" was not found.', $name));
        }

        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException(sprintf('LLM fixture "%s" must decode to an object.', $name));
        }

        if (($payload['error_code'] ?? null) === self::QUOTA_STRUCTURAL_CODE) {
            throw new ToolExecutionException($this->quotaError());
        }

        /** @var array<string, mixed> $payload */

        return $payload;
    }

    private function fixturesDirectory(): string
    {
        if ($this->fixturesDir !== null) {
            return $this->fixturesDir;
        }

        return dirname(__DIR__) . '/fixtures/llm';
    }
}
