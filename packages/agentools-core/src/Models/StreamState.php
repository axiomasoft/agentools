<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\InvocationId;
use AgenToolsPlatform\Core\Common\IntegrityConflict;

/** In-memory protocol validation, not durable delivery/checkpoint storage. */
final class StreamState
{
    private int $sequence = 0;
    private string $text = '';
    private ModelUsage $usage;
    private ?ModelResponse $terminal = null;
    /** @var array<string, array{tool: string, arguments: string}> */
    private array $proposalDeltas = [];

    public function __construct(public readonly InvocationId $invocationId)
    {
        $this->usage = new \AgenToolsPlatform\Core\Models\ModelUsage();
    }

    public function accept(ModelEvent $event): void
    {
        $this->invocationId->assertSame($event->invocationId);
        if ($this->terminal !== null || $event->sequence !== $this->sequence + 1
            || (($this->sequence === 0) !== ($event->kind === EventKind::Started))) {
            throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Stream sequence/terminal conflict');
        }
        if ($event->response !== null && !$event->response->partial
            && in_array($event->response->finishReason, [FinishReason::Completed, FinishReason::ToolProposal], true)) {
            foreach ($this->proposalDeltas as $id => $delta) {
                $matched = false;
                foreach ($event->response->proposals as $proposal) {
                    if ($proposal->providerProposalRef !== $id) { continue; }
                    try {
                        $arguments = json_decode($delta['arguments'], true, flags: JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Incomplete proposal at terminal');
                    }
                    if (!is_array($arguments) || !str_starts_with(ltrim($delta['arguments']), '{')) {
                        throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Proposal arguments must be an object');
                    }
                    $matched = $proposal->tool === $delta['tool']
                        && $proposal->arguments->sameValue(new \AgenToolsPlatform\Core\Models\JsonObject($arguments));
                }
                if (!$matched) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Terminal proposal differs from streamed identity/content'); }
            }
        }
        if ($event->kind === EventKind::ProposalDelta) {
            $id = $event->providerProposalRef;
            $tool = $event->tool;
            if ($id === null || $tool === null || $event->delta === null) { throw new \LogicException('Invalid proposal delta'); }
            if (isset($this->proposalDeltas[$id]) && $this->proposalDeltas[$id]['tool'] !== $tool) {
                throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Proposal reference changed tool');
            }
            $this->proposalDeltas[$id] = ['tool' => $tool, 'arguments' => ($this->proposalDeltas[$id]['arguments'] ?? '') . $event->delta];
        }
        if ($event->kind === EventKind::TextDelta) { $this->text .= $event->delta; }
        if ($event->usage !== null) { $this->usage = $this->usage->mergedWith($event->usage); }
        if ($event->response !== null) { $this->terminal = $event->response; $this->usage = $this->usage->mergedWith($event->response->usage); }
        $this->sequence = $event->sequence;
    }

    /** A lost terminal preserves partial data; callers must reconcile the invocation. */
    public function isUnknown(): bool { return $this->terminal === null || $this->terminal->finishReason === FinishReason::Unknown; }

    public function text(): string { return $this->text; }
    public function usage(): ModelUsage { return $this->usage; }
    public function terminal(): ?ModelResponse { return $this->terminal; }
    /** @return array<string, array{tool: string, arguments: string}> */
    public function proposalDeltas(): array { return $this->proposalDeltas; }
}
