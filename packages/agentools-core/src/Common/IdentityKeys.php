<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

/** Derives new assistant identities only. Dated Action/effect IDs remain opaque. */
final readonly class IdentityKeys
{
    public function __construct(private CanonicalHasher $hasher)
    {
    }

    public function turn(SourceIdentity $source): TurnId
    {
        return new \AgenToolsPlatform\Core\Common\TurnId($source->instance->tenant,
            $this->hasher->hash($source->instance->tenant, 'turn', $source->toArray())->value);
    }

    public function run(TurnId $turn): RunId
    {
        return new \AgenToolsPlatform\Core\Common\RunId($turn->tenant,
            $this->hasher->hash($turn->tenant, 'initial-run', $turn->toArray())->value);
    }

    public function invocation(RunId $run, string $stepKey, int $attempt): InvocationId
    {
        if ($stepKey === '' || $attempt < 1 || $attempt > 9007199254740991) {
            throw new \InvalidArgumentException('A persisted step key and invocation attempt are required');
        }
        return new \AgenToolsPlatform\Core\Common\InvocationId($run->tenant,
            $this->hasher->hash($run->tenant, 'invocation', [$run->toArray(), $stepKey, $attempt])->value);
    }

    /** Exactly one saved provider ID or saved normalized ordinal; never guess on retry. */
    public function proposal(InvocationId $invocation, ?string $providerId, ?int $persistedOrdinal): ProposalId
    {
        if (($providerId === null) === ($persistedOrdinal === null) || $providerId === ''
            || ($persistedOrdinal !== null && ($persistedOrdinal < 0 || $persistedOrdinal > 9007199254740991))) {
            throw new \InvalidArgumentException('A stable provider ID or persisted ordinal is required');
        }
        return new \AgenToolsPlatform\Core\Common\ProposalId($invocation->tenant,
            $this->hasher->hash($invocation->tenant, 'proposal', [$invocation->toArray(), $providerId, $persistedOrdinal])->value);
    }

    public function effectKey(ActionId $action, int $ordinal): PayloadHash
    {
        if ($ordinal < 0 || $ordinal > 9007199254740991) {
            throw new \InvalidArgumentException('A persisted effect ordinal is required');
        }
        return $this->hasher->hash($action->tenant, 'effect-key', [$action->toArray(), $ordinal]);
    }

    public function delivery(RunId $run, int $responseVersion, Ref $destination): DeliveryId
    {
        $run->tenant->assertSame($destination->tenant);
        if ($responseVersion < 1 || $responseVersion > 9007199254740991) {
            throw new \InvalidArgumentException('Response version must be positive');
        }
        return new \AgenToolsPlatform\Core\Common\DeliveryId($run->tenant,
            $this->hasher->hash($run->tenant, 'delivery', [$run->toArray(), $responseVersion, $destination->toArray()])->value);
    }

    public function usage(InvocationId $invocation, int $version): UsageVersionId
    {
        if ($version < 1 || $version > 9007199254740991) {
            throw new \InvalidArgumentException('Usage version must be positive');
        }
        return new \AgenToolsPlatform\Core\Common\UsageVersionId($invocation->tenant,
            $this->hasher->hash($invocation->tenant, 'usage-version', [$invocation->toArray(), $version])->value);
    }

    public function occurrence(Ref $schedule, UtcInstant $instant): PayloadHash
    {
        return $this->hasher->hash($schedule->tenant, 'occurrence', [$schedule->toArray(), $instant->value]);
    }
}
