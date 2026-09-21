<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

/** Maps confirmation proposals to journal payloads and supersede sequences. */
final class ConfirmationJournalSupport
{
    /** @return array<string, mixed> */
    public static function requestedPayload(Proposal $proposal): array
    {
        $payload = [
            'proposal_id' => $proposal->proposalId,
            'proposal_version' => $proposal->proposalVersion,
            'state' => $proposal->state->value,
            'deadline' => $proposal->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
        if ($proposal->supersededBy !== null) {
            $payload['superseded_by'] = $proposal->supersededBy;
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function editJournalPayloads(Proposal $original, Proposal $successor): array
    {
        return [
            self::requestedPayload($original),
            self::requestedPayload($original->supersede($successor->proposalId)),
            self::requestedPayload($successor),
        ];
    }
}
