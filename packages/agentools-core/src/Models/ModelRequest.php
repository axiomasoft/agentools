<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\InvocationId;
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\UtcInstant;

final readonly class ModelRequest
{
    /**
     * @param array<array-key, ModelMessage> $messages
     * @param array<array-key, ToolDefinition> $tools
     * @param array<array-key, ModelOption> $options
     */
    public function __construct(public InvocationId $invocationId, public array $messages,
        public Ref $contextSnapshotRef, public PayloadHash $contextSnapshotHash, public Ref $credentialRef,
        public UtcInstant $deadline, public ?CancellationSignal $cancellation = null,
        public string $system = '', public string $policy = '', public array $tools = [],
        public ?OutputSchema $outputSchema = null, public GenerationOptions $generation = new \AgenToolsPlatform\Core\Models\GenerationOptions(),
        public array $options = [], public CacheIntent $cache = new \AgenToolsPlatform\Core\Models\CacheIntent(), public ?ContinuationRef $continuation = null)
    {
        if ($messages === [] || !array_is_list($messages) || !array_is_list($tools) || !array_is_list($options)) {
            throw new \InvalidArgumentException('Model collections must be lists, with at least one message');
        }
        foreach ([$contextSnapshotRef, $credentialRef, $cache->key, $continuation?->encryptedRef] as $ref) {
            if ($ref !== null) { $invocationId->tenant->assertSame($ref->tenant); }
        }
        foreach ($messages as $message) {
            foreach ($message->proposals as $proposal) { $invocationId->tenant->assertSame($proposal->invocationId->tenant); }
            foreach ($message->parts as $part) {
                if ($part->ref !== null) { $invocationId->tenant->assertSame($part->ref->tenant); }
            }
        }
        $names = [];
        foreach ($tools as $tool) {
            if (in_array($tool->name, $names, true)) { throw new \InvalidArgumentException('Duplicate projected tool'); }
            $names[] = $tool->name;
        }
    }

    /** @return list<Capability> */
    public function requiredCapabilities(bool $stream = false): array
    {
        $capabilities = [Capability::Text];
        foreach ($this->messages as $message) {
            foreach ($message->parts as $part) { $capabilities[] = $part->capability(); }
            if ($message->role === MessageRole::Tool || $message->proposals !== []) { $capabilities[] = Capability::Tools; }
        }
        foreach ($this->options as $option) { $capabilities[] = $option->capability(); }
        if ($this->tools !== []) { $capabilities[] = Capability::Tools; }
        if ($this->outputSchema !== null) { $capabilities[] = Capability::StructuredOutput; }
        if ($this->cache->mode !== CacheMode::Bypass) { $capabilities[] = Capability::Cache; }
        if ($this->continuation !== null) { $capabilities[] = Capability::Continuation; }
        if ($stream) { $capabilities[] = Capability::Streaming; }
        return $capabilities;
    }
}
