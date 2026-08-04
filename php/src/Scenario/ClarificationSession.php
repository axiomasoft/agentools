<?php

declare(strict_types=1);

namespace AgenTools\Scenario;

use AgenTools\Error\ToolErrorCode;

/** CLR-6–CLR-15: a single MRTR binding with opaque state and round snapshots. */
final class ClarificationSession
{
    /** @var list<array<string, mixed>> */
    private array $rounds = [];

    /** @var array<string, mixed>|null */
    private ?array $requests = null;

    private ?string $requestState = null;
    private ?string $requestCallId = null;

    /** @param array<string, mixed> $requests */
    public function request(string $callId, string $requestState, array $requests): void
    {
        if ($callId === '' || $requestState === '' || $requests === []) {
            throw new \InvalidArgumentException('An MRTR request needs call id, opaque state, and requests.');
        }
        $this->requestCallId = $callId;
        $this->requestState = $requestState;
        $this->requests = $requests;
        $this->rounds[] = ['round' => count($this->rounds) + 1, 'direction' => 'request', 'call_id' => $callId, 'request_state' => $requestState, 'input_requests' => $requests];
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    public function answer(string $callId, array $answers): array
    {
        if ($this->requests === null || $this->requestState === null || $this->requestCallId === null) {
            throw new \LogicException('No pending clarification request exists.');
        }
        if ($callId === '' || $callId === $this->requestCallId) {
            throw new \InvalidArgumentException('An MRTR retry must use a distinct call id.');
        }
        foreach ($answers as $key => $_) {
            if (!array_key_exists($key, $this->requests)) {
                throw new \InvalidArgumentException(ToolErrorCode::UnknownResultType->value . ': response key was not requested.');
            }
        }
        $record = ['round' => count($this->rounds), 'direction' => 'response', 'call_id' => $callId, 'request_state' => $this->requestState, 'input_responses' => $answers];
        $this->rounds[] = $record;
        $this->requests = null;
        return $record;
    }

    /** @return list<array<string, mixed>> */
    public function rounds(): array { return $this->rounds; }
}
