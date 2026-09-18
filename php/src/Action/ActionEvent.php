<?php

declare(strict_types=1);

namespace AgenTools\Action;

/** Immutable envelope for one append-only action journal record. */
final readonly class ActionEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $eventId,
        public string $actionId,
        public int $seq,
        public int $expectedPrevSeq,
        public string $timestamp,
        public Actor $actor,
        public string $kind,
        public int $eventVersion,
        public string $specRevision,
        public array $payload,
        public ?int $epoch = null,
        public ?string $previousHash = null,
    ) {
        if ($eventId === '' || $actionId === '' || $timestamp === '' || $kind === '' || $specRevision === '') {
            throw new \InvalidArgumentException('The event envelope has required empty fields.');
        }
        if ($seq < 1 || $expectedPrevSeq < 0 || $eventVersion < 1 || ($epoch !== null && $epoch < 1)) {
            throw new \InvalidArgumentException('The event envelope contains an invalid numeric value.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'event_id' => $this->eventId,
            'action_id' => $this->actionId,
            'seq' => $this->seq,
            'expected_prev_seq' => $this->expectedPrevSeq,
            'ts' => $this->timestamp,
            'actor' => $this->actor->toArray(),
            'kind' => $this->kind,
            'event_version' => $this->eventVersion,
            'spec_rev' => $this->specRevision,
            'epoch' => $this->epoch,
            'prev_hash' => $this->previousHash,
            'payload' => $this->payload,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * ALC-36 input: the immutable event envelope without its chain link.
     *
     * @return array<string, mixed>
     */
    public function hashInput(): array
    {
        $input = $this->toArray();
        unset($input['prev_hash']);
        return $input;
    }

    public function withPreviousHash(?string $previousHash): self
    {
        return new self(
            $this->eventId,
            $this->actionId,
            $this->seq,
            $this->expectedPrevSeq,
            $this->timestamp,
            $this->actor,
            $this->kind,
            $this->eventVersion,
            $this->specRevision,
            $this->payload,
            $this->epoch,
            $previousHash,
        );
    }
}
