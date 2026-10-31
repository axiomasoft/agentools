<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

/** Technical nullable invocation facts. Canonical metering and money remain external. */
final readonly class UsageVersion
{
    public function __construct(
        public UsageVersionId $id,
        public InvocationId $invocationId,
        public int $version,
        public PayloadHash $factsHash,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public Certainty $certainty,
    ) {
        $id->tenant->assertSame($invocationId->tenant);
        if ($version < 1 || $version > 9007199254740991
            || (($certainty === Certainty::Unknown) !== ($inputTokens === null || $outputTokens === null))) {
            throw new \InvalidArgumentException('Missing usage stays null; partial observed facts are retained');
        }
        foreach ([$inputTokens, $outputTokens] as $tokens) {
            if ($tokens !== null && ($tokens < 0 || $tokens > 9007199254740991)) {
                throw new \InvalidArgumentException('Token facts must be nonnegative safe integers');
            }
        }
    }

    public function assertReplay(self $other): void
    {
        $this->id->assertSame($other->id);
        $this->invocationId->assertSame($other->invocationId);
        $this->factsHash->assertSame($other->factsHash);
        if ($this->version !== $other->version || $this->inputTokens !== $other->inputTokens
            || $this->outputTokens !== $other->outputTokens || $this->certainty !== $other->certainty) {
            throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Usage version facts conflict');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id->toArray(), 'invocation_id' => $this->invocationId->toArray(), 'version' => $this->version,
            'facts_hash' => $this->factsHash->value, 'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens, 'certainty' => $this->certainty->value];
    }
}
