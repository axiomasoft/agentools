<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Anthropic;

use AgenToolsPlatform\Core\Models\{ContentPart, Deployment, EventKind, FinishReason, ModelEvent, ModelRequest, ModelResponse, ModelUsage};

/** Wire state only. No handlers, domain stores or second model exchange. */
final class StreamMachine
{
    private ?\stdClass $message = null;
    /** @var array<int, \stdClass> */ private array $blocks = [];
    /** @var array<int, bool> */ private array $open = [];
    /** @var array<int, string> */ private array $fragments = [];
    /** @var array<string, bool> */ private array $proposalIds = [];
    private bool $stopped = false;
    private ?string $reason = null;
    public string $text = '';
    public ModelUsage $usage;
    public ?string $model = null;
    private int $sequence = 1;
    public function __construct(private readonly ModelRequest $request, private readonly Deployment $deployment,
        private readonly ResponseDecoder $decoder) { $this->usage = new ModelUsage(); }
    public function event(EventKind $kind, ?string $delta = null, ?string $id = null, ?string $tool = null, ?ModelUsage $usage = null, ?ModelResponse $response = null): ModelEvent
    {
        return new ModelEvent($this->request->invocationId, ++$this->sequence, $kind, $delta, $id, $tool, $usage, $response);
    }
    /** @return list<ModelEvent> */
    public function accept(\stdClass $event): array
    {
        $type = Fields::text($event, 'type');
        if ($type === 'ping') { return []; }
        if ($this->stopped) { throw new ProtocolFailure('duplicate_or_late_terminal'); }
        if ($type === 'error') { throw new ProtocolFailure('stream_provider_error'); }
        if ($type === 'message_start') {
            if ($this->message !== null) { throw new ProtocolFailure('duplicate_start'); }
            $message = Fields::object($event, 'message');
            if (($message->type ?? null) !== 'message' || ($message->role ?? null) !== 'assistant' || Fields::items($message, 'content') !== []) { throw new ProtocolFailure('invalid_start'); }
            Fields::text($message, 'id');
            $this->model = Fields::text($message, 'model'); $this->message = $message;
            $this->usage = $this->usage->mergedWith($this->decoder->usage($message->usage ?? null));
            return [$this->event(EventKind::Usage, usage: $this->usage)];
        }
        if ($this->message === null) { throw new ProtocolFailure('missing_start'); }
        if ($type === 'content_block_start') {
            if ($this->reason !== null) { throw new ProtocolFailure('block_after_finish'); }
            $index = Fields::index($event);
            if (isset($this->blocks[$index]) || $index !== count($this->blocks)) { throw new ProtocolFailure('block_sequence'); }
            $block = Fields::object($event, 'content_block');
            if (($block->type ?? null) === 'text') {
                $delta = Fields::text($block, 'text', true); $this->text .= $delta;
                $out = $delta === '' ? [] : [$this->event(EventKind::TextDelta, $delta)];
            } elseif (($block->type ?? null) === 'tool_use') {
                $id = Fields::text($block, 'id'); $tool = Fields::text($block, 'name'); $input = Fields::object($block, 'input');
                if (isset($this->proposalIds[$id])) { throw new ProtocolFailure('proposal_id_conflict'); }
                $this->proposalIds[$id] = true;
                $this->fragments[$index] = get_object_vars($input) === [] ? '' : json_encode($input, JSON_THROW_ON_ERROR);
                $out = [$this->event(EventKind::ProposalDelta, $this->fragments[$index], $id, $tool)];
            } else { throw new ProtocolFailure('unsupported_response_block'); }
            $this->blocks[$index] = $block; $this->open[$index] = true;
            return $out;
        }
        if ($type === 'content_block_delta' || $type === 'content_block_stop') {
            $index = Fields::index($event);
            if (!isset($this->open[$index])) { throw new ProtocolFailure('delta_or_stop_outside_block'); }
            $block = $this->blocks[$index];
            if ($type === 'content_block_stop') {
                $out = [];
                if ($block->type === 'tool_use') {
                    if ($this->fragments[$index] === '') {
                        $this->fragments[$index] = '{}';
                        $out[] = $this->event(EventKind::ProposalDelta, '{}', Fields::text($block, 'id'), Fields::text($block, 'name'));
                    }
                    $input = json_decode($this->fragments[$index], false, 64, JSON_THROW_ON_ERROR);
                    if (!$input instanceof \stdClass) { throw new ProtocolFailure('invalid_tool_json'); }
                    $block->input = $input;
                }
                unset($this->open[$index]); return $out;
            }
            $delta = Fields::object($event, 'delta');
            if (($delta->type ?? null) === 'text_delta' && $block->type === 'text') {
                $value = Fields::text($delta, 'text', true); $block->text = Fields::text($block, 'text', true) . $value; $this->text .= $value;
                return [$this->event(EventKind::TextDelta, $value)];
            }
            if (($delta->type ?? null) === 'input_json_delta' && $block->type === 'tool_use') {
                $value = Fields::text($delta, 'partial_json', true); $this->fragments[$index] .= $value;
                return [$this->event(EventKind::ProposalDelta, $value, Fields::text($block, 'id'), Fields::text($block, 'name'))];
            }
            throw new ProtocolFailure('invalid_delta_type');
        }
        if ($type === 'message_delta') {
            if ($this->open !== []) { throw new ProtocolFailure('unclosed_blocks'); }
            $delta = Fields::object($event, 'delta');
            $reason = $delta->stop_reason ?? null;
            if ($reason !== null) {
                if (!is_string($reason) || ($this->reason !== null && $this->reason !== $reason)) { throw new ProtocolFailure('finish_conflict'); }
                $this->reason = $reason;
            }
            $this->usage = $this->usage->mergedWith($this->decoder->usage($event->usage ?? null));
            return [$this->event(EventKind::Usage, usage: $this->usage)];
        }
        if ($type === 'message_stop') {
            if ($this->open !== [] || $this->reason === null) { throw new ProtocolFailure('incomplete_terminal'); }
            $this->stopped = true; return [];
        }
        // Additive unrecognized event types carry no portable state or dispatch authority.
        return [];
    }
    public function finish(?string $requestId): ModelResponse
    {
        if (!$this->stopped || $this->message === null) { throw new ProtocolFailure('missing_terminal'); }
        $message = clone $this->message;
        $message->content = array_values($this->blocks); $message->stop_reason = $this->reason;
        unset($message->usage);
        return $this->decoder->decode($message, $this->request, $this->deployment, $requestId, $this->usage);
    }
    public function observe(\stdClass $raw): void
    {
        if (is_string($raw->model ?? null) && $raw->model !== '') { $this->model = $raw->model; }
        $this->usage = $this->usage->mergedWith($this->decoder->usage($raw->usage ?? null));
        if (is_array($raw->content ?? null)) {
            foreach ($raw->content as $part) { if ($part instanceof \stdClass && ($part->type ?? null) === 'text' && is_string($part->text ?? null)) { $this->text .= $part->text; } }
        }
    }
}