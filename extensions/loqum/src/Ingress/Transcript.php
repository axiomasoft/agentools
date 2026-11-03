<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Ingress;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref, UtcInstant};

/** Host-normalized STT metadata. Text bytes stay in the host's immutable payload store. */
final readonly class Transcript
{
    public function __construct(public string $eventId, public Ref $realtimeSession, public int $mediaEpoch,
        public string $utteranceId, public int $revision, public string $locale,
        public UtcInstant $startedAt, public UtcInstant $endedAt, public Ref $textRef,
        public PayloadHash $textHash, public int $contextRevision, public string $idempotencyKey,
        public bool $final = true, public string $channel = 'voice')
    {
        foreach ([$eventId, $utteranceId, $idempotencyKey] as $id) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,255}\z/D', $id) !== 1) {
                throw new \InvalidArgumentException('Bounded transcript identity required');
            }
        }
        foreach ([$mediaEpoch, $revision, $contextRevision] as $version) {
            if ($version < 1 || $version > 9007199254740991) {
                throw new \InvalidArgumentException('Positive interoperable transcript version required');
            }
        }
        $textRef->tenant->assertSame($realtimeSession->tenant);
        if (preg_match('/\A[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*\z/D', $locale) !== 1
            || !in_array($channel, ['voice', 'text-fallback'], true)
            || new \DateTimeImmutable($startedAt->value) > new \DateTimeImmutable($endedAt->value)) {
            throw new \InvalidArgumentException('Invalid transcript locale, channel or time interval');
        }
    }

    /**
     * Transport event, epoch, realtime ref, channel and retry key cannot create another input.
     * @return array<string, mixed>
     */
    public function content(): array
    {
        return ['utterance_id' => $this->utteranceId, 'revision' => $this->revision, 'locale' => $this->locale,
            'started_at' => $this->startedAt->value, 'ended_at' => $this->endedAt->value,
            'text_ref' => $this->textRef->toArray(), 'text_hash' => $this->textHash->value,
            'context_revision' => $this->contextRevision];
    }
}
