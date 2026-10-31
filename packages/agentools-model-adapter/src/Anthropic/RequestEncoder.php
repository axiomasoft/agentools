<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Anthropic;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Models\{Capability, Deployment, MessageRole, ModelRequest};

final readonly class RequestEncoder
{
    public function __construct(private SchemaRules $schemas) {}
    public function encode(ModelRequest $request, Deployment $deployment, bool $stream): string
    {
        if ($deployment->provider !== 'anthropic' || $request->generation->temperature !== null || $request->generation->topP !== null || $request->options !== []) {
            throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        }
        foreach ($request->requiredCapabilities($stream) as $capability) {
            if (!in_array($capability, [Capability::Text, Capability::Tools, Capability::StructuredOutput, Capability::Streaming], true)) {
                throw new ContractViolation(ErrorCode::CapabilityUnavailable);
            }
        }
        $tools = [];
        foreach ($request->tools as $tool) {
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $tool->name) !== 1) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
            $tools[] = ['name' => $tool->name, 'description' => $tool->description, 'input_schema' => $this->schemas->prepare($tool->inputSchema, false)];
        }
        $messages = [];
        $pending = [];
        foreach ($request->messages as $message) {
            if ($message->role === MessageRole::Assistant && $pending !== []) { throw new ContractViolation(ErrorCode::InvalidInput); }
            $parts = [];
            foreach ($message->parts as $part) {
                if ($part->text === null) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
                $parts[] = ['type' => 'text', 'text' => $part->text];
            }
            foreach ($message->proposals as $proposal) {
                if (isset($pending[$proposal->providerProposalRef])) { throw new ContractViolation(ErrorCode::IntegrityConflict); }
                $parts[] = ['type' => 'tool_use', 'id' => $proposal->providerProposalRef, 'name' => $proposal->tool, 'input' => Json::object($proposal->arguments)];
                $pending[$proposal->providerProposalRef] = true;
            }
            if ($message->role === MessageRole::Tool) {
                $id = $message->proposalRef;
                if ($id === null || !isset($pending[$id])) { throw new ContractViolation(ErrorCode::InvalidInput); }
                unset($pending[$id]);
                $parts = [['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $parts]];
            } elseif ($message->role === MessageRole::User && $pending !== []) {
                throw new ContractViolation(ErrorCode::InvalidInput);
            }
            $role = $message->role === MessageRole::Assistant ? 'assistant' : 'user';
            $last = count($messages) - 1;
            if ($last >= 0 && $messages[$last]['role'] === $role) { $messages[$last]['content'] = [...$messages[$last]['content'], ...$parts]; }
            else { $messages[] = ['role' => $role, 'content' => $parts]; }
        }
        if ($pending !== []) { throw new ContractViolation(ErrorCode::InvalidInput); }
        $body = ['model' => $deployment->model, 'messages' => $messages, 'max_tokens' => $request->generation->maxOutputTokens, 'stream' => $stream];
        $system = [];
        foreach ([$request->system, $request->policy] as $boundary) { if ($boundary !== '') { $system[] = ['type' => 'text', 'text' => $boundary]; } }
        if ($system !== []) { $body['system'] = $system; }
        if ($tools !== []) { $body['tools'] = $tools; $body['tool_choice'] = ['type' => 'auto']; }
        if ($request->outputSchema !== null) {
            if (!$request->outputSchema->strict) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $this->schemas->prepare($request->outputSchema->schema, true)]];
        }
        try { return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
        catch (\JsonException) { throw new ContractViolation(ErrorCode::InvalidInput); }
    }
}