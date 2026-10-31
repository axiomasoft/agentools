<?php

declare(strict_types=1);

/**
 * P1.2 offline contract probe, NOT the P4 ModelTransport adapter.
 * JSON on stdin; exactly one JSON result on stdout. There is no network client,
 * tool registry, callback executor, retry loop, credential lookup or persistence.
 * `io_calls` counts consumption of a scripted HTTP response, never sockets.
 */
namespace AgenToolsP12Probe;

use RuntimeException;
use stdClass;

function requireRule(bool $condition, string $code): void
{
    if (!$condition) {
        throw new RuntimeException($code);
    }
}

function canonical(mixed $value): mixed
{
    if ($value instanceof stdClass) {
        $fields = get_object_vars($value);
        ksort($fields, SORT_STRING);
        return (object) array_map(canonical(...), $fields);
    }
    if (is_array($value)) {
        return array_map(canonical(...), $value);
    }
    return $value;
}

function identity(string $tenant, string $invocation, string $providerRef): string
{
    requireRule($tenant !== '' && $invocation !== '' && $providerRef !== '', 'MISSING_IDENTITY');
    return hash('sha256', json_encode(['p12-proposal-v1', $tenant, $invocation, $providerRef], JSON_THROW_ON_ERROR));
}

function select(stdClass $document): array
{
    $target = $document->selected_transport ?? new stdClass;
    $driver = $target->driver ?? null;
    requireRule(in_array($driver, ['direct', 'laravel-ai'], true), 'UNKNOWN_DRIVER');
    if ($driver === 'direct') {
        requireRule(property_exists($target, 'package') && property_exists($target, 'version')
            && $target->package === null && $target->version === null, 'FABRICATED_SDK_PIN');
        requireRule(($document->supported_loop_mode ?? null) === 'separate-transport', 'LOOP_OWNER_MISMATCH');
        // An offline transport experiment cannot certify an implemented durable loop.
        requireRule(($document->loop_proven ?? null) === false, 'UNPROVEN_LOOP');
    } else {
        requireRule(($target->package ?? null) === 'laravel/ai'
            && preg_match('/^\d+\.\d+\.\d+$/D', $target->version ?? '') === 1, 'FABRICATED_SDK_PIN');
        // P1.2 has no qualified SDK loop. A version label or self-asserted boolean
        // cannot override the observed terminal loss or establish durable recovery.
        throw new RuntimeException('UNPROVEN_SDK_LOOP');
    }
    requireRule(!empty($target->source_ref) && !empty($target->rationale), 'MISSING_SOURCE_EVIDENCE');
    $names = [];
    $required = 0;
    foreach ($document->capabilities ?? [] as $cap) {
        requireRule(!isset($names[$cap->name]), 'DUPLICATE_CAPABILITY');
        $names[$cap->name] = true;
        requireRule(in_array($cap->support ?? '', ['supported', 'unsupported', 'unknown'], true), 'INVALID_SUPPORT');
        if ($cap->required_for_loop ?? false) {
            ++$required;
            requireRule($cap->support === 'supported', $cap->support === 'unknown' ? 'UNKNOWN_REQUIRED_CAPABILITY' : 'UNSUPPORTED_REQUIRED_CAPABILITY');
            requireRule(in_array($cap->evidence_class ?? '', ['source_inspection', 'executed_contract'], true)
                && !empty($cap->evidence_refs), 'UNVERIFIED_REQUIRED_CAPABILITY');
        }
    }
    requireRule($required > 0, 'MISSING_REQUIRED_CAPABILITIES');
    return ['choice' => 'C', 'driver' => 'anthropic-messages', 'protocol' => '2026-10-21', 'loop_owner' => 'AgenTools', 'loop_proven' => false];
}

function prepare(stdClass $input): array
{
    $request = $input->request;
    requireRule(empty($input->automatic_effects) && empty($request->callbacks), 'AUTOMATIC_EFFECTS_FORBIDDEN');
    requireRule(($request->cancelled ?? false) === false, 'CANCELLED');
    requireRule(is_int($request->deadline ?? null) && $request->deadline > $input->now, 'DEADLINE_EXCEEDED');
    requireRule(($request->admin_state ?? '') === 'enabled', 'ADMIN_STATE_BLOCKED');
    requireRule(($request->authorized ?? false) && ($request->emergency_guard_allows ?? false), 'CURRENT_GUARD_DENIED');
    $credential = $request->credential_ref ?? new stdClass;
    $deployment = $request->deployment ?? new stdClass;
    foreach (['tenant_id', 'account_id', 'provider', 'region'] as $key) {
        requireRule(is_string($credential->$key ?? null) && $credential->$key !== ''
            && $credential->$key === ($deployment->$key ?? null), 'CREDENTIAL_BINDING_MISMATCH');
    }
    requireRule(($request->tenant_id ?? '') === $credential->tenant_id && !empty($credential->ref), 'CREDENTIAL_BINDING_MISMATCH');
    requireRule($credential->provider === 'anthropic', 'UNSUPPORTED_PROVIDER');
    requireRule(!empty($request->invocation_id) && !empty($request->snapshot_ref), 'MISSING_IDENTITY');
    requireRule(!empty($request->model), 'MODEL_REQUIRED');
    $snapshot = $input->snapshot ?? new stdClass;
    requireRule(is_int($snapshot->expires_at ?? null) && $snapshot->expires_at > $input->now, 'EXPIRED_EVIDENCE');
    foreach ($request->capabilities ?? [] as $name) {
        $capability = $snapshot->capabilities->$name ?? new stdClass;
        requireRule(($capability->support ?? 'unknown') === 'supported', ($capability->support ?? 'unknown') === 'unsupported' ? 'UNSUPPORTED_CAPABILITY' : 'UNKNOWN_CAPABILITY');
        requireRule(($capability->evidence ?? '') === 'probe_verified', 'UNVERIFIED_CAPABILITY');
        requireRule(in_array($name, ['text', 'tools', 'structured', 'stream'], true), 'UNSUPPORTED_CAPABILITY');
    }
    requireRule(is_array($request->messages ?? null) && count($request->messages) > 0, 'INVALID_MESSAGES');
    foreach ($request->messages as $message) {
        requireRule(in_array($message->role ?? '', ['user', 'assistant'], true), 'INVALID_MESSAGE_ROLE');
        // This probe's text vertical accepts strings or client tool replay parts.
        requireRule(is_string($message->content ?? null) || is_array($message->content ?? null), 'INVALID_MESSAGE_PART');
        if (is_array($message->content)) {
            foreach ($message->content as $part) {
                requireRule(in_array($part->type ?? '', ['text', 'tool_use', 'tool_result'], true), 'UNSUPPORTED_CAPABILITY');
            }
        }
    }
    requireRule(is_int($request->max_tokens ?? null) && $request->max_tokens > 0, 'INVALID_GENERATION_LIMIT');
    $tools = [];
    foreach ($request->tools ?? [] as $tool) {
        requireRule(!property_exists($tool, 'type') && !property_exists($tool, 'handle')
            && !property_exists($tool, 'callback'), 'AUTOMATIC_EFFECTS_FORBIDDEN');
        requireRule(preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $tool->name ?? '') === 1
            && !isset($tools[$tool->name]) && ($tool->input_schema->type ?? '') === 'object', 'INVALID_TOOL_SCHEMA');
        $tools[$tool->name] = true;
    }
    $options = $request->provider_options ?? new stdClass;
    requireRule(count(get_object_vars($options)) === 0, 'UNSUPPORTED_PROVIDER_OPTION');
    $body = ['model' => $request->model, 'max_tokens' => $request->max_tokens, 'messages' => $request->messages];
    if (isset($request->system)) {
        requireRule(is_string($request->system), 'INVALID_POLICY_BOUNDARY');
        $body['system'] = $request->system;
    }
    if ($tools !== []) {
        requireRule(in_array('tools', $request->capabilities, true), 'UNDECLARED_CAPABILITY');
        $body['tools'] = $request->tools;
        $body['tool_choice'] = ['type' => 'auto'];
    }
    if (isset($request->structured_schema)) {
        requireRule(in_array('structured', $request->capabilities, true), 'UNDECLARED_CAPABILITY');
        $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $request->structured_schema]];
    }
    // Only a trusted credential REF is exposed in evidence. No secret is resolved.
    return ['method' => 'POST', 'url' => 'https://api.anthropic.com/v1/messages',
        'headers' => ['anthropic-version' => '2026-10-21', 'content-type' => 'application/json', 'x-api-key' => '[trusted-ref]'],
        'credential_ref' => $credential->ref, 'body' => $body];
}

function usage(?stdClass $data): array
{
    $out = [];
    foreach (['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens'] as $key) {
        $value = $data->$key ?? null;
        requireRule($value === null || (is_int($value) && $value >= 0), 'INVALID_USAGE');
        $out[$key] = $value;
    }
    return $out;
}

function normalize(stdClass $response, stdClass $request): array
{
    requireRule(($response->type ?? '') === 'message' && !empty($response->id) && !empty($response->model), 'MALFORMED_RESPONSE');
    requireRule(is_array($response->content ?? null), 'MALFORMED_RESPONSE');
    $proposals = [];
    $seen = [];
    $text = '';
    $toolNames = array_map(fn ($t) => $t->name, $request->tools ?? []);
    foreach ($response->content as $block) {
        if (($block->type ?? '') === 'text') {
            requireRule(is_string($block->text ?? null), 'MALFORMED_RESPONSE');
            $text .= $block->text;
        } elseif (($block->type ?? '') === 'tool_use') {
            requireRule(is_string($block->id ?? null) && $block->id !== '' && $block->input instanceof stdClass
                && in_array($block->name ?? '', $toolNames, true), 'INVALID_PROPOSAL');
            $key = identity($request->tenant_id, $request->invocation_id, $block->id);
            $hash = hash('sha256', json_encode(canonical($block), JSON_THROW_ON_ERROR));
            requireRule(!isset($seen[$key]) || $seen[$key] === $hash, 'PROPOSAL_HASH_CONFLICT');
            if (!isset($seen[$key])) {
                $proposals[] = ['identity' => $key, 'provider_ref' => $block->id, 'name' => $block->name, 'arguments' => $block->input, 'hash' => $hash];
                $seen[$key] = $hash;
            }
        } else {
            throw new RuntimeException('UNSUPPORTED_RESPONSE_PART');
        }
    }
    $reason = $response->stop_reason ?? null;
    requireRule(in_array($reason, ['end_turn', 'stop_sequence', 'tool_use', 'max_tokens', 'refusal', 'model_context_window_exceeded'], true), 'UNKNOWN_FINISH_REASON');
    requireRule(($proposals === []) === ($reason !== 'tool_use'), 'INCONSISTENT_PROPOSAL_FINISH');
    $structured = null;
    if (isset($request->structured_schema) && $reason === 'end_turn') {
        $structured = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        requireRule($structured instanceof stdClass, 'INVALID_STRUCTURED_RESULT');
        // Deliberately bounded fixture schema validation; production JSON Schema
        // dialect/validator belongs to P4.2, not to this research harness.
        $schema = $request->structured_schema;
        foreach ($schema->required ?? [] as $key) {
            requireRule(property_exists($structured, $key), 'INVALID_STRUCTURED_RESULT');
        }
        foreach (get_object_vars($structured) as $key => $value) {
            $property = $schema->properties->$key ?? null;
            requireRule($property !== null || ($schema->additionalProperties ?? true), 'INVALID_STRUCTURED_RESULT');
            if (($property->type ?? null) === 'string') {
                requireRule(is_string($value), 'INVALID_STRUCTURED_RESULT');
            }
        }
    }
    return ['text' => $text, 'proposals' => $proposals, 'structured' => $structured, 'finish_reason' => $reason,
        'refusal' => $reason === 'refusal', 'provider_message_id' => $response->id,
        'requested_model' => $request->model, 'actual_model' => $response->model,
        'usage' => usage($response->usage ?? null), 'adapter_version' => 'p12-offline-probe-v1'];
}

function stream(string $wire, stdClass $request): array
{
    $started = false;
    $terminal = false;
    $blocks = [];
    $open = [];
    $fragments = [];
    $events = [];
    $message = null;
    $reason = null;
    $counts = new stdClass;
    $wire = str_replace("\r\n", "\n", $wire);
    requireRule(str_ends_with($wire, "\n\n"), 'INCOMPLETE_SSE_FRAME');
    foreach (explode("\n\n", trim($wire)) as $frame) {
        $type = null;
        $data = [];
        foreach (explode("\n", $frame) as $line) {
            if (str_starts_with($line, 'event:')) { $type = trim(substr($line, 6)); }
            if (str_starts_with($line, 'data:')) { $data[] = ltrim(substr($line, 5)); }
        }
        if ($data === []) { continue; }
        $event = json_decode(implode("\n", $data), false, 512, JSON_THROW_ON_ERROR);
        requireRule(($event->type ?? null) === $type, 'SSE_TYPE_MISMATCH');
        if ($terminal) { throw new RuntimeException($type === 'message_stop' ? 'DUPLICATE_TERMINAL' : 'EVENT_AFTER_TERMINAL'); }
        if ($type === 'ping') { continue; }
        if ($type === 'error') { throw new RuntimeException('STREAM_PROVIDER_ERROR'); }
        $normalized = ['sequence' => count($events), 'type' => $type, 'data' => $event];
        if ($type === 'message_start') {
            requireRule(!$started, 'DUPLICATE_STREAM_START');
            $started = true;
            $message = $event->message;
            $counts = $message->usage ?? new stdClass;
        } else {
            requireRule($started, 'MISSING_STREAM_START');
            if ($type === 'content_block_start') {
                $i = $event->index;
                requireRule(is_int($i) && $i >= 0 && !isset($blocks[$i]), 'DUPLICATE_BLOCK');
                requireRule(in_array($event->content_block->type ?? '', ['text', 'tool_use'], true), 'UNSUPPORTED_RESPONSE_PART');
                $blocks[$i] = $event->content_block;
                $open[$i] = true;
            } elseif ($type === 'content_block_delta') {
                $i = $event->index;
                requireRule($open[$i] ?? false, 'DELTA_OUTSIDE_BLOCK');
                $delta = $event->delta;
                if (($delta->type ?? '') === 'text_delta' && $blocks[$i]->type === 'text') {
                    $blocks[$i]->text .= $delta->text;
                } elseif (($delta->type ?? '') === 'input_json_delta' && $blocks[$i]->type === 'tool_use') {
                    $fragments[$i] = ($fragments[$i] ?? '').$delta->partial_json;
                } else { throw new RuntimeException('UNKNOWN_STREAM_DELTA'); }
            } elseif ($type === 'content_block_stop') {
                $i = $event->index;
                requireRule($open[$i] ?? false, 'DUPLICATE_BLOCK_STOP');
                if (isset($fragments[$i])) { $blocks[$i]->input = json_decode($fragments[$i], false, 512, JSON_THROW_ON_ERROR); }
                unset($open[$i]);
            } elseif ($type === 'message_delta') {
                requireRule($open === [], 'UNCLOSED_BLOCK');
                $reason = $event->delta->stop_reason ?? $reason;
                foreach (get_object_vars($event->usage ?? new stdClass) as $key => $value) { $counts->$key = $value; }
                $normalized['usage'] = usage($counts); // cumulative update, never sum
            } elseif ($type === 'message_stop') {
                requireRule($open === [] && $reason !== null, 'INCOMPLETE_TERMINAL');
                $terminal = true;
            } else { throw new RuntimeException('UNKNOWN_STREAM_EVENT'); }
        }
        $events[] = $normalized;
    }
    requireRule($terminal, 'MISSING_TERMINAL');
    ksort($blocks);
    $message->content = array_values($blocks);
    $message->stop_reason = $reason;
    $message->usage = $counts;
    return ['events' => $events, 'response' => normalize($message, $request)];
}

$ioCalls = 0;
try {
    $input = json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR);
    requireRule($input instanceof stdClass, 'INVALID_INPUT');
    $selection = select($input->selection ?? json_decode(file_get_contents(__DIR__.'/../docs/platform/sdk-capabilities.json')));
    $op = $input->op ?? 'select';
    if ($op === 'select') {
        $output = $selection;
    } elseif ($op === 'identity') {
        $output = ['identity' => identity($input->tenant, $input->invocation, $input->provider_ref)];
    } else {
        requireRule(in_array($op, ['exchange', 'stream'], true), 'UNKNOWN_OPERATION');
        $prepared = prepare($input);
        if ($op === 'stream') {
            requireRule(in_array('stream', $input->request->capabilities, true), 'UNDECLARED_CAPABILITY');
            $prepared['body']['stream'] = true;
        }
        ++$ioCalls;
        $reply = $input->http;
        if (isset($reply->fault)) { throw new RuntimeException('NETWORK_OUTCOME_UNKNOWN'); }
        requireRule(($reply->status ?? 0) === 200, ($reply->status ?? 0) === 429 ? 'RATE_LIMITED' : 'PROVIDER_HTTP_ERROR');
        $output = $op === 'stream' ? stream($reply->body, $input->request) : ['response' => normalize($reply->body, $input->request)];
        $output['request'] = $prepared;
        $output['provider_request_id'] = $reply->request_id ?? null;
    }
    echo json_encode(['ok' => true, 'result' => $output, 'io_calls' => $ioCalls, 'effects' => 0], JSON_THROW_ON_ERROR)."\n";
} catch (\Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error instanceof \JsonException ? 'MALFORMED_JSON' : $error->getMessage(),
        'io_calls' => $ioCalls, 'effects' => 0], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
