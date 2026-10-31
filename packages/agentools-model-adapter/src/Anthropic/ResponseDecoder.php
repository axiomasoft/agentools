<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Anthropic;

use AgenToolsPlatform\Core\Models\{ContentPart, Deployment, FinishReason, JsonObject, ModelRequest, ModelResponse, ModelUsage, StructuredValue, ToolProposal};

final readonly class ResponseDecoder
{
    public function __construct(private SchemaRules $schemas) {}
    public function usage(mixed $raw): ModelUsage
    {
        if ($raw === null) { return new ModelUsage(); }
        if (!$raw instanceof \stdClass) { throw new ProtocolFailure('invalid_usage'); }
        $values = [];
        foreach (['input_tokens','output_tokens','cache_read_input_tokens','cache_creation_input_tokens'] as $key) {
            $value = $raw->{$key} ?? null;
            if ($value !== null && (!is_int($value) || $value < 0 || $value > 9007199254740991)) { throw new ProtocolFailure('invalid_usage'); }
            $values[] = $value;
        }
        return new ModelUsage(...$values);
    }
    public function actual(Deployment $requested, string $model): Deployment
    {
        return new Deployment($requested->ref, $requested->provider, $requested->account, $requested->region, $model, []);
    }
    public function decode(\stdClass $raw, ModelRequest $request, Deployment $deployment, ?string $requestId, ?ModelUsage $usage = null): ModelResponse
    {
        if (($raw->type ?? null) !== 'message' || ($raw->role ?? null) !== 'assistant') { throw new ProtocolFailure('invalid_message'); }
        Fields::text($raw, 'id');
        $model = Fields::text($raw, 'model');
        $usage = ($usage ?? new ModelUsage())->mergedWith($this->usage($raw->usage ?? null));
        $text = '';
        $proposals = [];
        $proposalFacts = [];
        foreach (Fields::items($raw, 'content') as $block) {
            if (!$block instanceof \stdClass) { throw new ProtocolFailure('invalid_block'); }
            if (($block->type ?? null) === 'text') { $text .= Fields::text($block, 'text', true); }
            elseif (($block->type ?? null) === 'tool_use') {
                $id = Fields::text($block, 'id'); $name = Fields::text($block, 'name'); $input = Fields::object($block, 'input');
                $definition = null;
                foreach ($request->tools as $tool) { if ($tool->name === $name) { $definition = $tool; break; } }
                if ($definition === null || !$this->schemas->accepts($input, $this->schemas->prepare($definition->inputSchema, false))) { throw new ProtocolFailure('invalid_proposal'); }
                $fields = [];
                foreach (get_object_vars($input) as $key => $value) { $fields[$key] = Json::portable($value); }
                $proposal = new ToolProposal($request->invocationId, $id, $name, new JsonObject($fields));
                $facts = json_encode($this->ordered((object) ['tool' => $name, 'input' => $input]), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                if (isset($proposalFacts[$id]) && $proposalFacts[$id] !== $facts) {
                    throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Provider proposal reference has differing content');
                }
                $proposalFacts[$id] = $facts;
                $proposals[$id] = $proposal;
            } else { throw new ProtocolFailure('unsupported_response_block'); }
        }
        $finish = match ($raw->stop_reason ?? null) {
            'end_turn', 'stop_sequence' => FinishReason::Completed,
            'tool_use' => FinishReason::ToolProposal,
            'max_tokens', 'model_context_window_exceeded' => FinishReason::Length,
            'refusal' => FinishReason::Refusal,
            default => throw new ProtocolFailure('unknown_finish_reason'),
        };
        if (($finish === FinishReason::ToolProposal && $proposals === []) || ($finish === FinishReason::Completed && $proposals !== [])) { throw new ProtocolFailure('inconsistent_tool_finish'); }
        if (!in_array($finish, [FinishReason::Completed, FinishReason::ToolProposal], true)) { $proposals = []; }
        $structured = null;
        if ($request->outputSchema !== null && $finish === FinishReason::Completed) {
            try { $value = json_decode($text, false, 64, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new ProtocolFailure('invalid_structured_json'); }
            if (!$this->schemas->accepts($value, $this->schemas->prepare($request->outputSchema->schema, true))) { throw new ProtocolFailure('invalid_structured_schema'); }
            $structured = new StructuredValue(Json::portable($value));
        }
        return new ModelResponse($request->invocationId, $deployment, $this->actual($deployment, $model), $requestId, $finish,
            parts: $text === '' ? [] : [ContentPart::text($text)], proposals: array_values($proposals), structured: $structured,
            refusal: $finish === FinishReason::Refusal ? ($text === '' ? 'provider_refusal' : $text) : null,
            usage: $usage, adapterVersion: Transport::VERSION, partial: $finish === FinishReason::Length);
    }
    private function ordered(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value); ksort($fields, SORT_STRING); $object = new \stdClass();
            foreach ($fields as $key => $entry) { $object->{(string) $key} = $this->ordered($entry); }
            return $object;
        }
        if (is_array($value)) { return array_map($this->ordered(...), $value); }
        return $value;
    }
}
