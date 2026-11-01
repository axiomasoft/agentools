<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\{ActionId, CanonicalHasher, IdentityKeys, PayloadHash, ProposalId, UtcInstant};
use AgenToolsPlatform\Core\Models\{FinishReason, ModelResponse, ModelUsage};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;

/** One normalized immutable exchange. Recovery decodes saved mappings, never calls the model. */
final readonly class ExchangeResult
{
    /** @param list<SavedProposal> $proposals */
    private function __construct(public string $json, public FinishReason $finish, public ModelUsage $usage,
        public array $proposals, public bool $partial, public ?int $requestCount) {}

    public static function response(InvocationPlan $plan, ModelResponse $response, CanonicalHasher $hasher): self
    {
        $response->assertFor($plan->request, $plan->deployment);
        $keys = new \AgenToolsPlatform\Core\Common\IdentityKeys($hasher);
        $normalized = [];
        if ($response->finishReason === FinishReason::ToolProposal && !$response->partial) {
            foreach ($response->proposals as $ordinal => $proposal) {
                $definition = $plan->tools[$proposal->tool] ?? throw new \InvalidArgumentException('Unprojected proposal');
                $id = $keys->proposal($response->invocationId, $proposal->providerProposalRef, null);
                $normalized[] = ['id' => $id->value, 'action_id' => $id->value, 'ordinal' => $ordinal,
                    'provider_id' => $proposal->providerProposalRef, 'definition' => $definition->toArray(),
                    'arguments_json' => json_encode((object) $proposal->arguments->value, JSON_THROW_ON_ERROR),
                    'input_hash' => $hasher->hash($id->tenant, 'action-input', (object) $proposal->arguments->value)->value,
                    'deadline' => $plan->request->deadline->value];
            }
        }
        return self::decode($plan, json_encode(['request_hash' => $plan->requestHash->value, 'response' => $response, 'normalized' => $normalized], JSON_THROW_ON_ERROR), $hasher);
    }

    public static function unknown(InvocationPlan $plan, CanonicalHasher $hasher): self
    {
        return self::decode($plan, json_encode(['request_hash' => $plan->requestHash->value, 'response' => ['invocationId' => $plan->request->invocationId,
            'finishReason' => 'unknown', 'usage' => new \AgenToolsPlatform\Core\Models\ModelUsage(), 'partial' => true], 'normalized' => []], JSON_THROW_ON_ERROR), $hasher);
    }

    public static function decode(InvocationPlan $plan, string $json, CanonicalHasher $hasher): self
    {
        // The owner enforces disclosure; the journal verifies this payload's hash before decoding.
        $data = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['response'] ?? null) || !is_array($data['normalized'] ?? null)
            || !array_is_list($data['normalized'])) { throw new \UnexpectedValueException('Invalid saved exchange'); }
        if (($data['request_hash'] ?? null) !== $plan->requestHash->value) { throw new \UnexpectedValueException('Saved request hash changed'); }
        $response = $data['response'];
        $invocation = $response['invocationId'] ?? null;
        $tenant = is_array($invocation) ? ($invocation['tenant'] ?? null) : null;
        if (!is_array($invocation) || !is_array($tenant) || ($tenant['value'] ?? null) !== $plan->runId->tenant->value
            || ($invocation['value'] ?? null) !== $plan->request->invocationId->value) {
            throw new \UnexpectedValueException('Saved invocation changed');
        }
        $finish = FinishReason::from(self::text($response, 'finishReason'));
        $units = $response['usage'] ?? null;
        if (!is_array($units) || !is_bool($response['partial'] ?? null)) { throw new \UnexpectedValueException('Invalid saved usage'); }
        $usage = new \AgenToolsPlatform\Core\Models\ModelUsage(self::unit($units, 'inputTokens'), self::unit($units, 'outputTokens'),
            self::unit($units, 'cacheReadTokens'), self::unit($units, 'cacheWriteTokens'));
        $proposals = []; $seen = [];
        foreach ($data['normalized'] as $ordinal => $saved) {
            if (!is_array($saved) || ($saved['ordinal'] ?? null) !== $ordinal || !is_array($saved['definition'] ?? null)) {
                throw new \UnexpectedValueException('Saved proposal ordinal changed');
            }
            $def = $saved['definition'];
            $allowed = false;
            foreach ($plan->tools as $pinned) { if ($pinned->toArray() === $def) { $allowed = true; } }
            $providerId = self::text($saved, 'provider_id');
            if (!$allowed || in_array($providerId, $seen, true) || self::text($saved, 'id') !== self::text($saved, 'action_id')) {
                throw new \UnexpectedValueException('Saved mapping changed');
            }
            $seen[] = $providerId;
            $arguments = json_decode(self::text($saved, 'arguments_json'), false, 128, JSON_THROW_ON_ERROR);
            if (!$arguments instanceof \stdClass) { throw new \UnexpectedValueException('Saved input must be an object'); }
            $inputHash = new \AgenToolsPlatform\Core\Common\PayloadHash(self::text($saved, 'input_hash'));
            $inputHash->assertSame($hasher->hash($plan->runId->tenant, 'action-input', $arguments));
            $proposals[] = new \AgenToolsPlatform\Core\Runtime\SavedProposal(new \AgenToolsPlatform\Core\Common\ProposalId($plan->runId->tenant, self::text($saved, 'id')),
                new \AgenToolsPlatform\Core\Common\ActionId($plan->runId->tenant, self::text($saved, 'action_id')), $ordinal, self::text($saved, 'provider_id'),
                new \AgenToolsPlatform\Core\Profiles\DefinitionRef(self::text($def, 'id'), self::text($def, 'version'), new \AgenToolsPlatform\Core\Common\PayloadHash(self::text($def, 'hash'))),
                self::text($saved, 'arguments_json'), new \AgenToolsPlatform\Core\Common\PayloadHash(self::text($saved, 'input_hash')),
                new \AgenToolsPlatform\Core\Common\UtcInstant(self::text($saved, 'deadline')));
        }
        if (($finish !== FinishReason::ToolProposal || $response['partial']) && $proposals !== []) {
            throw new \UnexpectedValueException('Partial or unknown proposals are not dispatchable');
        }
        return new \AgenToolsPlatform\Core\Runtime\ExchangeResult($json, $finish, $usage, $proposals, $response['partial'],
            (!$response['partial'] && in_array($finish, [FinishReason::Completed, FinishReason::ToolProposal], true))
                || is_string($response['providerRequestId'] ?? null) ? 1 : null);
    }

    public function hash(): PayloadHash { return new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:' . hash('sha256', $this->json)); }
    public function accepted(): bool { return !$this->partial && in_array($this->finish, [FinishReason::Completed, FinishReason::ToolProposal], true); }

    /** @param array<array-key, mixed> $data */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) { throw new \UnexpectedValueException('Missing saved text'); }
        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function unit(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || ($data[$key] !== null && !is_int($data[$key]))) {
            throw new \UnexpectedValueException('Missing saved unit is not zero');
        }
        return $data[$key];
    }
}
