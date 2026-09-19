<?php

declare(strict_types=1);

namespace AgenTools\Reference\Cli;

/** Renders delivered protocol objects without interpreting gate semantics. */
final class DeliveryRenderer
{
    /** @param array<string, mixed> $object */
    public function render(array $object): string
    {
        $type = is_string($object['type'] ?? null) ? $object['type'] : 'unknown';

        return match ($type) {
            'clarification' => $this->renderClarification($object),
            'preview' => $this->renderPreview($object),
            'confirmation_request' => sprintf("[confirmation] tool=%s awaiting decision\n", $this->stringField($object, 'tool')),
            'confirmation_resolved' => sprintf("[confirmation] resolved decision=%s\n", $this->stringField($object, 'decision')),
            'proposal' => $this->renderProposal($object),
            'tool_boundary' => sprintf("[tool] %s phase=%s\n", $this->stringField($object, 'tool'), $this->stringField($object, 'phase')),
            'status' => sprintf("[status] %s\n", $this->stringField($object, 'message')),
            default => sprintf("[delivery] type=%s\n", $type),
        };
    }

    /** @param array<string, mixed> $object */
    private function renderClarification(array $object): string
    {
        $rounds = is_array($object['rounds'] ?? null) ? $object['rounds'] : [];
        $lines = ["[clarification] rounds=" . count($rounds)];

        return implode('', array_map(static fn (string $line): string => $line . "\n", $lines));
    }

    /** @param array<string, mixed> $object */
    private function renderPreview(array $object): string
    {
        $preview = is_array($object['preview'] ?? null) ? $object['preview'] : [];
        $summary = is_string($preview['summary'] ?? null) ? $preview['summary'] : '';
        $changes = is_array($preview['changes'] ?? null) ? $preview['changes'] : [];
        $body = '';
        if ($changes !== [] && is_array($changes[0])) {
            $body = is_string($changes[0]['value'] ?? null) ? $changes[0]['value'] : '';
        }

        return sprintf("[preview] summary=%s body=%s\n", $summary, $body);
    }

    /** @param array<string, mixed> $object */
    private function renderProposal(array $object): string
    {
        $payload = is_array($object['payload'] ?? null) ? $object['payload'] : [];
        $state = is_string($payload['state'] ?? null) ? $payload['state'] : 'unknown';
        $id = is_string($payload['proposal_id'] ?? null) ? $payload['proposal_id'] : '';

        return sprintf("[proposal] id=%s state=%s\n", $id, strtoupper($state));
    }

    /** @param array<string, mixed> $object */
    private function stringField(array $object, string $field): string
    {
        $value = $object[$field] ?? '';

        return is_string($value) ? $value : '';
    }
}
