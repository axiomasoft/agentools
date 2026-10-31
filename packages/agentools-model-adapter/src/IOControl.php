<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, UtcInstant};
use AgenToolsPlatform\Core\Models\{Deployment, ModelRequest, Time};

final readonly class IOControl
{
    /** @param \Closure(): UtcInstant $now */
    public function __construct(public ModelRequest $request, public Deployment $deployment,
        private CurrentGuard $guard, private \Closure $now, private bool $stream = false) {}

    public function now(): UtcInstant { return ($this->now)(); }
    public function check(): void
    {
        \AgenToolsPlatform\Core\Models\TransportPreflight::check($this->request, $this->deployment, $this->now(), $this->stream);
        if ($this->request->cancellation?->isCancellationRequested()) { throw new ContractViolation(ErrorCode::Cancelled); }
        if (Time::compare($this->now(), $this->request->deadline) >= 0) { throw new ContractViolation(ErrorCode::DeadlineExceeded); }
        $this->guard->assertAllowed($this->request, $this->deployment);
    }

    public function remainingMilliseconds(): int
    {
        $now = new \DateTimeImmutable($this->now()->value);
        $end = new \DateTimeImmutable($this->request->deadline->value);
        $milliseconds = (float) $end->format('U.u') * 1000 - (float) $now->format('U.u') * 1000;
        return max(1, (int) ceil(min($milliseconds, 300000)));
    }
}
