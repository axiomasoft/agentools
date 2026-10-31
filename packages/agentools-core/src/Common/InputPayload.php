<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

/** The explicit stable hash envelope: no transport timestamp or authorization flags. */
final readonly class InputPayload
{
    public function __construct(
        public Ref $inputRef,
        public PayloadHash $contentHash,
        public ?Ref $conversationRef = null,
        public ?Ref $messageRef = null,
        public ?Ref $utteranceRef = null,
    ) {
        foreach ([$conversationRef, $messageRef, $utteranceRef] as $ref) {
            if ($ref !== null) {
                $inputRef->tenant->assertSame($ref->tenant);
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['input_ref' => $this->inputRef->toArray(), 'content_hash' => $this->contentHash->value,
            'conversation_ref' => $this->conversationRef?->toArray(),
            'message_ref' => $this->messageRef?->toArray(), 'utterance_ref' => $this->utteranceRef?->toArray()];
    }
}
