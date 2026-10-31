<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Models;

use AgenTools\Platform\Models\{LegacyModelMapper, LegacyExchangeMapper};
use AgenToolsPlatform\Core\Models\{Capability, Deployment, FinishReason, JsonObject, ModelRequest, ModelResponse, ToolProposal};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};

/** Explicit test host contract for the actual scripted fixtures, never production autoload. */
final readonly class LegacyFixtureMapper implements LegacyModelMapper, LegacyExchangeMapper
{
    /** @param array<string, mixed> $envelope */
    public function __construct(private array $envelope) {}
    public function request(array $input): ModelRequest
    {
        if ($input !== $this->envelope) { throw new \InvalidArgumentException('Input changed'); }
        return ModelFixtures::request();
    }
    public function response(ModelResponse $response): array
    {
        if ($response->partial || $response->error !== null || $response->finishReason === FinishReason::Unknown || !$response->structured instanceof JsonObject) {
            throw new \RuntimeException('Legacy outcome is not representable');
        }
        return $response->structured->value;
    }
    public function validate(ModelRequest $request, Deployment $deployment): void
    {
        if ($request->options !== [] || $request->tools !== [] || $request->outputSchema !== null) {
            throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        }
        foreach ($request->requiredCapabilities() as $capability) {
            if ($capability !== Capability::Text) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
        }
    }
    public function input(ModelRequest $request): array { return $this->envelope; }
    public function output(array $output, ModelRequest $request, Deployment $deployment): ModelResponse
    {
        $proposals = [];
        $call = $output['toolCall'] ?? null;
        if (is_array($call) && is_string($call['name'] ?? null) && is_array($call['arguments'] ?? null)) {
            /** @var array<string, mixed> $arguments */
            $arguments = $call['arguments'];
            $proposals[] = new ToolProposal($request->invocationId, 'legacy:' . $request->invocationId->value . ':0', $call['name'], new JsonObject($arguments));
        }
        return new ModelResponse($request->invocationId, $deployment, null, null,
            $proposals === [] ? FinishReason::Completed : FinishReason::ToolProposal, proposals: $proposals,
            structured: new JsonObject($output), adapterVersion: 'scripted-legacy-fixture/1');
    }
}
