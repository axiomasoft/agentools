<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

use AgenToolsPlatform\Core\Common\{InstanceId, Ref, SessionId, UtcInstant};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Immutable versioned daily wall-clock rule. This is configuration, never a perpetual permission. */
final readonly class Schedule
{
    public function __construct(public Ref $id, public int $version, public InstanceId $instance,
        public SessionId $session, public Ref $actor, public ?Ref $subject, public string $purpose,
        public Ref $template, public string $timezone, public string $wallTime,
        public UtcInstant $startsAt, public UtcInstant $endsAt,
        public string $overlap, public string $gap, public string $misfire, public int $catchupLimit = 3,
        public int $graceSeconds = 60, public ?Ref $actingAs = null, public ?Ref $agentPrincipal = null)
    {
        foreach ([$instance, $session, $actor, $subject, $template, $actingAs, $agentPrincipal] as $ref) {
            if ($ref !== null) { $id->tenant->assertSame($ref->tenant); }
        }
        if ($version < 1 || $version > 9007199254740991
            || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $purpose) !== 1
            || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D', $wallTime) !== 1
            || !in_array($overlap, ['choose-first','choose-last','reject'], true)
            || !in_array($gap, ['skip','reject'], true)
            || !in_array($misfire, ['skip','one','latest','catchup'], true)
            || $catchupLimit < 1 || $catchupLimit > 100 || $graceSeconds < 0 || $graceSeconds > 3600
            || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Explicit bounded schedule policies required');
        }
        $start = new \DateTimeImmutable($startsAt->value); $end = new \DateTimeImmutable($endsAt->value);
        if ($end <= $start || $end > $start->modify('+3660 days')) {
            throw new \InvalidArgumentException('Active range must be positive and at most 3660 days');
        }
    }

    public function assertPrincipal(ExecutionContext $context): void
    {
        $this->session->assertSame($context->session);
        $this->actor->assertSame($context->identity->actorRef);
        if ($this->purpose !== $context->purpose || $this->subject?->value !== $context->identity->subjectRef?->value
            || $this->actingAs?->value !== $context->identity->actingAs?->value
            || $this->agentPrincipal?->value !== $context->identity->agentPrincipalRef?->value) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(\AgenToolsPlatform\Core\Common\ErrorCode::AccessDenied);
        }
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return ['tenant' => $this->id->tenant->value, 'id' => $this->id->value, 'version' => $this->version,
            'instance' => $this->instance->value, 'session' => $this->session->value, 'actor' => $this->actor->value,
            'subject' => $this->subject?->value, 'purpose' => $this->purpose, 'template' => $this->template->value,
            'timezone' => $this->timezone, 'wall_time' => $this->wallTime, 'starts_at' => $this->startsAt->value,
            'ends_at' => $this->endsAt->value, 'overlap' => $this->overlap, 'gap' => $this->gap,
            'misfire' => $this->misfire, 'catchup_limit' => $this->catchupLimit, 'grace_seconds' => $this->graceSeconds,
            'acting_as' => $this->actingAs?->value, 'agent_principal' => $this->agentPrincipal?->value];
    }
}
