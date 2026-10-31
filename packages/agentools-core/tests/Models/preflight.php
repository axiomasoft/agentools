<?php

declare(strict_types=1);

use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Models\ModelFixtures as F;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, UtcInstant};
use AgenToolsPlatform\Core\Models\{Capability, CapabilityEvidence, CancellationSignal, ContinuationRef, Deployment, Evidence, JsonObject, ModelRequest, ProviderOption, Support, TransportPreflight, VisionDetail, VisionOption};

return [
    'CM-028' => static function (): void {
        $case = F::fixture('CM-028');
        $base = F::request(); $deployment = F::deployment();
        $caps = $deployment->capabilities;
        $caps['vision'] = new CapabilityEvidence(Support::from(F::string($case, 'support')), Evidence::Documented,
            new UtcInstant('2026-10-30T00:00:00Z'), new UtcInstant('2026-11-01T00:00:00Z'), 'fixture');
        $deployment = new Deployment($deployment->ref, 'provider', 'account-A', 'eu', 'model-1', $caps);
        $request = new ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash,
            $base->credentialRef, $base->deadline, options: [new VisionOption(VisionDetail::from(F::string($case, 'vision_detail')))]);
        $io = 0;
        H::rejects(static function () use ($request, $deployment, &$io): void {
            TransportPreflight::check($request, $deployment, F::now()); ++$io;
        }, ContractViolation::class, ErrorCode::CapabilityUnavailable);
        H::check($io === $case['io_calls']);
    },
    'CM-029' => static function (): void {
        $case = F::fixture('CM-029');
        $ref = new ContinuationRef(F::ref('encrypted-state'), 'provider', 'account-A', 'eu', new UtcInstant(F::string($case, 'expires_at')));
        H::check(is_array($case['attempts']));
        if (!is_array($case['attempts'])) { throw new RuntimeException('Missing attempts'); }
        foreach ($case['attempts'] as $attempt) {
            if (!is_array($attempt)) { throw new RuntimeException('Invalid attempt'); }
            $deployment = F::deployment(F::string($attempt, 'tenant'), F::string($attempt, 'account'), F::string($attempt, 'region'));
            $now = new UtcInstant(F::string($attempt, 'now'));
            if ($attempt['allowed']) { $ref->assertUsable($deployment, $now); }
            else { H::rejects(static fn () => $ref->assertUsable($deployment, $now), ContractViolation::class, ErrorCode::AccessDenied); }
        }
        H::rejects(static fn () => $ref->assertUsable(new Deployment(F::ref('other'), 'other-provider', 'account-A', 'eu', 'm', []), F::now()), ContractViolation::class, ErrorCode::AccessDenied);
    },
    'models.evidence-unknown-expired' => static function (): void {
        foreach ([[Support::Unknown, Evidence::Documented, '2026-11-01T00:00:00Z'],
            [Support::Supported, Evidence::Unverified, '2026-11-01T00:00:00Z'],
            [Support::Supported, Evidence::Documented, '2026-10-31T12:00:00Z']] as [$support, $tier, $expiry]) {
            $deployment = new Deployment(F::ref('deployment-1'), 'provider', 'account-A', 'eu', 'model-1', [
                'text' => new CapabilityEvidence($support, $tier, new UtcInstant('2026-10-30T00:00:00Z'), new UtcInstant($expiry), 'fixture'),
            ]);
            H::rejects(static fn () => TransportPreflight::check(F::request(), $deployment, F::now()), ContractViolation::class, ErrorCode::CapabilityUnavailable);
        }
        H::rejects(static fn () => TransportPreflight::check(F::request(), new Deployment(F::ref('d'), 'p', 'a', 'r', 'm', []), F::now()), ContractViolation::class, ErrorCode::CapabilityUnavailable);
    },
    'models.cancel-deadline-before-io' => static function (): void {
        $base = F::request();
        TransportPreflight::check($base, F::deployment(), F::now());
        H::rejects(static fn () => TransportPreflight::check($base, F::deployment(), $base->deadline), ContractViolation::class, ErrorCode::DeadlineExceeded);
        $signal = new class implements CancellationSignal { public function isCancellationRequested(): bool { return true; } };
        $request = new ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline, $signal);
        H::rejects(static fn () => TransportPreflight::check($request, F::deployment(), F::now()), ContractViolation::class, ErrorCode::Cancelled);
    },
    'models.provider-option-binding' => static function (): void {
        $base = F::request();
        $request = new ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash,
            $base->credentialRef, $base->deadline, options: [new ProviderOption('other-provider', 'reasoning', new JsonObject(['budget' => 1]))]);
        H::rejects(static fn () => TransportPreflight::check($request, F::deployment(), F::now()), ContractViolation::class, ErrorCode::CapabilityUnavailable);
    },
];
