<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

final readonly class PlatformError
{
    public function __construct(
        public ErrorCode $code,
        public RetryClass $retryClass,
        public Certainty $certainty,
        public Ref $traceRef,
        public ?Ref $diagnosticRef = null,
    ) {
        if ($diagnosticRef !== null) {
            $traceRef->tenant->assertSame($diagnosticRef->tenant);
        }
        if (!in_array($retryClass, $code->allowedRetries(), true)
            || ($code->requiresUnknown() && $certainty !== Certainty::Unknown)
            || ($certainty === Certainty::Unknown && !in_array($retryClass, [RetryClass::StatusLookup, RetryClass::Manual], true))) {
            throw new \InvalidArgumentException('Unknown outcomes require lookup or manual resolution');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $error = ['code' => $this->code->value, 'retry_class' => $this->retryClass->value,
            'certainty' => $this->certainty->value, 'public_message_key' => 'agentools.platform.' . $this->code->value,
            'trace_ref' => $this->traceRef->toArray()];
        if ($this->diagnosticRef !== null) {
            $error['diagnostic_ref'] = $this->diagnosticRef->toArray();
        }
        return $error;
    }
}
