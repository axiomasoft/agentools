<?php

declare(strict_types=1);

namespace AgenTools\Ops;

/** Trace correlation stored in journal payload, not as a tracer dependency (AOB-17..AOB-22). */
final readonly class TraceCorrelation
{
    public function __construct(
        public string $traceId,
        public ?string $spanId = null,
        public ?string $linkedTraceId = null,
        public ?string $linkedSpanId = null,
    ) {
        if ($traceId === '') {
            throw new \InvalidArgumentException('Trace id is required.');
        }
    }

    /** AOB-20; close the prepare span when confirmation is requested. */
    /** @return array<string, mixed> */
    public function confirmationRequestedPayload(): array
    {
        return ['trace' => $this->toTraceBlock()];
    }

    /**
     * AOB-21; resume on commit with a span link, not by extending the prepare span.
     *
     * @return array<string, mixed>
     */
    public function commitResumePayload(): array
    {
        $trace = $this->toTraceBlock();
        if ($this->linkedTraceId !== null) {
            $trace['link'] = array_filter([
                'trace_id' => $this->linkedTraceId,
                'span_id' => $this->linkedSpanId,
            ]);
        }

        return ['trace' => $trace];
    }

    /** AOB-19; trace ids must not become action or idempotency identifiers. */
    public function mustNotBeActionIdentifier(): true
    {
        return true;
    }

    /** @return array<string, string> */
    private function toTraceBlock(): array
    {
        return array_filter([
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
        ]);
    }
}
