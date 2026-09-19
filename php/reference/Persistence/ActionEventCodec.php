<?php

declare(strict_types=1);

namespace AgenTools\Reference\Persistence;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\Actor;

/** Serializes action journal events for SQLite storage. */
final class ActionEventCodec
{
    /** @return array<string, mixed> */
    public static function toRow(ActionEvent $event): array
    {
        return [
            'event_id' => $event->eventId,
            'action_id' => $event->actionId,
            'seq' => $event->seq,
            'expected_prev_seq' => $event->expectedPrevSeq,
            'ts' => $event->timestamp,
            'actor_type' => $event->actor->type,
            'actor_id' => $event->actor->id,
            'kind' => $event->kind,
            'event_version' => $event->eventVersion,
            'spec_rev' => $event->specRevision,
            'epoch' => $event->epoch,
            'prev_hash' => $event->previousHash,
            'payload_json' => json_encode($event->payload, JSON_THROW_ON_ERROR),
        ];
    }

    /** @param array<mixed, mixed> $row */
    public static function fromRow(array $row): ActionEvent
    {
        $payload = json_decode(RowCodec::string($row, 'payload_json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Stored journal payload must decode to an object.');
        }
        /** @var array<string, mixed> $payload */

        return new ActionEvent(
            eventId: RowCodec::string($row, 'event_id'),
            actionId: RowCodec::string($row, 'action_id'),
            seq: RowCodec::int($row, 'seq'),
            expectedPrevSeq: RowCodec::int($row, 'expected_prev_seq'),
            timestamp: RowCodec::string($row, 'ts'),
            actor: new Actor(RowCodec::string($row, 'actor_type'), RowCodec::string($row, 'actor_id')),
            kind: RowCodec::string($row, 'kind'),
            eventVersion: RowCodec::int($row, 'event_version'),
            specRevision: RowCodec::string($row, 'spec_rev'),
            payload: $payload,
            epoch: isset($row['epoch']) && is_int($row['epoch']) ? $row['epoch'] : null,
            previousHash: isset($row['prev_hash']) && is_string($row['prev_hash']) ? $row['prev_hash'] : null,
        );
    }
}
