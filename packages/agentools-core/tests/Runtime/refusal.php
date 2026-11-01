<?php

declare(strict_types=1);

require_once __DIR__ . '/RefusalFixture.php';

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict};
use AgenToolsPlatform\Core\Runtime\{ExchangeResult, InvocationCheckpoint, InvocationRefusal};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Runtime\RefusalFixture;

return [
    'runtime.pre-provider-refusal-is-known-and-durable' => static function (): void {
        $f = new RefusalFixture();
        $f->security->policy->denied = ['content:data'];
        H::rejects(static fn () => $f->advance(), ContractViolation::class, ErrorCode::AccessDenied);
        H::check($f->provider->calls === 0);
        $saved = $f->journal->saved ?? throw new RuntimeException('Missing refusal');
        H::check($saved->stage() === 'refused');
        H::check($f->journal->outbounds === 0);
        H::check($saved->data['request_count'] === 0 && $saved->data['possible_cost'] === false);
        $f->security->policy->denied = [];
        H::rejects(static fn () => $f->advance(), ContractViolation::class, ErrorCode::AccessDenied);
        H::check($f->provider->calls === 0 && $f->journal->outbounds === 0);
    },
    'runtime.pre-provider-exact-denial-types' => static function (): void {
        foreach ([ErrorCode::StaleContext, ErrorCode::DeploymentUnavailable, ErrorCode::CapabilityUnavailable,
            ErrorCode::DeadlineExceeded, ErrorCode::IntegrityConflict] as $code) {
            $f = new RefusalFixture();
            match ($code) {
                ErrorCode::StaleContext => $f->security->policy->revision = 2,
                ErrorCode::DeploymentUnavailable => $f->security->policy->emergency = true,
                ErrorCode::CapabilityUnavailable => $f->security->policy->emergency = null,
                ErrorCode::DeadlineExceeded => $f->security->policy->time = $f->security->request->deadline,
                ErrorCode::IntegrityConflict => $f->security->policy->snapshots[$f->security->snapshot->ref()->value]
                    = new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:' . str_repeat('b', 64)),
            };
            $class = $code === ErrorCode::IntegrityConflict ? IntegrityConflict::class : ContractViolation::class;
            H::rejects(static fn () => $f->advance(), $class, $class === ContractViolation::class ? $code : null);
            $saved = $f->journal->saved ?? throw new RuntimeException('Missing refusal');
            H::check(InvocationRefusal::restore($saved)->code === $code);
            H::check($f->provider->calls === 0 && $f->journal->outbounds === 0);
            // Replay through a fresh Runtime retains the original type, even after the policy changes again.
            $f->security->policy->revision = 1; $f->security->policy->emergency = false;
            $f->security->policy->time = $f->security->core->now;
            H::rejects(static fn () => $f->advance(), $class, $class === ContractViolation::class ? $code : null);
        }
    },
    'runtime.revocation-during-outbound-is-proven-not-sent' => static function (): void {
        $f = new RefusalFixture();
        $f->journal->afterOutbound = static function () use ($f): void { $f->security->policy->denied = ['content:data']; };
        H::rejects(static fn () => $f->advance(), ContractViolation::class, ErrorCode::AccessDenied);
        H::check($f->provider->calls === 0 && $f->journal->outbounds === 1 && $f->journal->saved?->stage() === 'refused');
    },
    'runtime.provider-uncertainty-including-typed-errors-stays-unknown' => static function (): void {
        foreach ([new RuntimeException('lost provider reply'), new ContractViolation(ErrorCode::AccessDenied),
            new IntegrityConflict('provider-side conflict')] as $failure) {
            $f = new RefusalFixture(); $f->provider->failure = $failure;
            $saved = $f->advance();
            H::check($saved->stage() === 'unknown' && $saved->data['possible_cost'] === true && $saved->data['request_count'] === null);
            $ref = $saved->data['result_ref'] ?? null;
            if (!is_string($ref)) { throw new RuntimeException('Missing saved unknown exchange'); }
            $result = ExchangeResult::decode($f->plan, $f->payloads->get($f->security->core->context,
                $f->security->core->ref($ref)), $f->security->core->hasher);
            H::check($result->usage->inputTokens === null && $result->usage->outputTokens === null && $result->proposals === []);
            $f->provider->failure = null;
            H::check($f->advance()->stage() === 'unknown');
            H::check($f->provider->calls === 1 && $f->journal->outbounds === 1);
        }
    },
    'runtime.outbound-crash-does-not-become-refusal-or-resend' => static function (): void {
        $f = new RefusalFixture();
        $f->journal->afterOutbound = static function (): void { throw new RuntimeException('commit reply lost'); };
        H::rejects(static fn () => $f->advance(), RuntimeException::class);
        H::check($f->journal->saved?->stage() === 'outbound' && $f->provider->calls === 0);
        $f->security->policy->denied = ['content:data'];
        H::check($f->advance()->stage() === 'unknown');
        H::check($f->provider->calls === 0 && $f->journal->outbounds === 1);
    },
    'runtime.refusal-persistence-failure-after-outbound-remains-uncertain' => static function (): void {
        $f = new RefusalFixture();
        $f->journal->afterOutbound = static function () use ($f): void { $f->security->policy->denied = ['content:data']; };
        $f->journal->beforeRefusal = static function (): void { throw new RuntimeException('refusal commit failed'); };
        H::rejects(static fn () => $f->advance(), RuntimeException::class);
        H::check($f->journal->saved?->stage() === 'outbound');
        H::check($f->advance()->stage() === 'unknown' && $f->provider->calls === 0);
    },
    'runtime.refusal-persistence-before-outbound-does-not-invent-unknown' => static function (): void {
        $f = new RefusalFixture(); $f->security->policy->denied = ['content:data'];
        $f->journal->beforeRefusal = static function (): void { throw new RuntimeException('refusal commit failed'); };
        H::rejects(static fn () => $f->advance(), RuntimeException::class);
        H::check($f->journal->saved?->stage() === 'admitted' && $f->journal->outbounds === 0);
        $f->journal->beforeRefusal = null;
        H::rejects(static fn () => $f->advance(), ContractViolation::class, ErrorCode::AccessDenied);
        H::check($f->provider->calls === 0 && $f->journal->saved?->stage() === 'refused');
    },
    'runtime.refusal-does-not-disable-successful-exchanges' => static function (): void {
        $f = new RefusalFixture();
        H::check($f->advance()->stage() === 'response');
        H::check($f->advance()->stage() === 'response');
        H::check($f->provider->calls === 1 && $f->journal->outbounds === 1);
    },
    'runtime.refusal-replay-rejects-inconsistent-metadata' => static function (): void {
        $f = new RefusalFixture(); $f->security->policy->denied = ['content:data'];
        H::rejects(static fn () => $f->advance(), ContractViolation::class, ErrorCode::AccessDenied);
        $saved = $f->journal->saved ?? throw new RuntimeException('Missing refusal');
        foreach (['stage' => 'unknown', 'request_count' => null, 'possible_cost' => true,
            'refusal' => ['error_type' => 'integrity_conflict', 'error_code' => 'access_denied']] as $key => $value) {
            $data = $saved->data; $data[$key] = $value;
            H::rejects(static fn () => InvocationRefusal::restore(new InvocationCheckpoint($saved->lease, $data)), UnexpectedValueException::class);
        }
    },
];
