<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Common\Certainty;
use AgenToolsPlatform\Core\Common\DatedErrorMapping;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\PlatformError;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\RetryClass;
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\SpecFixtures;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/SpecFixtures.php';
$trace = new Ref(new TenantId('tenant-a'), 'trace-1');
$cases = [
    'error.taxonomy-contract' => static function (): void {
        $document = SpecFixtures::document(dirname(__DIR__, 4) . '/spec/platform/v1/errors.json');
        $codes = SpecFixtures::object($document['codes'] ?? null);
        foreach (ErrorCode::cases() as $code) {
            $rule = SpecFixtures::object($codes[$code->value] ?? null);
            H::check(array_map(static fn (RetryClass $retry): string => $retry->value, $code->allowedRetries()) === ($rule['allowed_retries'] ?? null));
            $dated = $rule['dated_codes'] ?? null;
            if (!is_array($dated)) {
                throw new RuntimeException('Missing dated error mapping');
            }
            foreach ($dated as $value) {
                $wire = SpecFixtures::string($value);
                H::check(\AgenTools\Error\ToolErrorCode::tryFrom($wire) !== null);
                H::check(DatedErrorMapping::fromDated($wire) === $code);
            }
        }
        H::check(count($codes) === count(ErrorCode::cases()));
    },
    'error.closed-vocabulary' => static function () use ($trace): void {
        $reflection = new ReflectionEnum(ErrorCode::class);
        H::check(count($reflection->getCases()) === 20 && !$reflection->hasCase('RawProviderFailure'));
        foreach (ErrorCode::cases() as $code) {
            $error = new PlatformError($code, $code->allowedRetries()[0], $code->requiresUnknown() ? Certainty::Unknown : Certainty::Known, $trace);
            H::check($error->toArray()['public_message_key'] === 'agentools.platform.' . $code->value);
        }
    },
    'error.unknown-retry-forbidden' => static function () use ($trace): void {
        foreach ([ErrorCode::InvocationUnknown, ErrorCode::EffectUnknown, ErrorCode::DeliveryUnknown, ErrorCode::ManualResolutionRequired] as $code) {
            H::rejects(static fn () => new PlatformError($code, RetryClass::SafeSameKey, Certainty::Unknown, $trace), InvalidArgumentException::class);
            H::rejects(static fn () => new PlatformError($code, RetryClass::StatusLookup, Certainty::Known, $trace), InvalidArgumentException::class);
        }
    },
    'error.retry-taxonomy' => static function () use ($trace): void {
        H::rejects(static fn () => new PlatformError(ErrorCode::AccessDenied, RetryClass::SafeSameKey, Certainty::Known, $trace), InvalidArgumentException::class);
        H::rejects(static fn () => new PlatformError(ErrorCode::ProviderTimeout, RetryClass::NewInvocation, Certainty::Unknown, $trace), InvalidArgumentException::class);
        H::check((new PlatformError(ErrorCode::EffectUnknown, RetryClass::Manual, Certainty::Unknown, $trace))->toArray()['certainty'] === 'unknown');
    },
    'error.public-redaction' => static function () use ($trace): void {
        $error = new PlatformError(ErrorCode::EffectUnknown, RetryClass::StatusLookup, Certainty::Unknown, $trace, new Ref($trace->tenant, 'diagnostic-1'));
        H::check(array_keys($error->toArray()) === ['code', 'retry_class', 'certainty', 'public_message_key', 'trace_ref', 'diagnostic_ref']);
        H::rejects(static fn () => new PlatformError(ErrorCode::EffectUnknown, RetryClass::Manual, Certainty::Unknown, $trace, new Ref(new TenantId('tenant-b'), 'diagnostic')), DomainException::class);
    },
    'error.dated-contextual-mapping' => static function (): void {
        H::check(DatedErrorMapping::fromDated('agentools.precondition.idempotency_ambiguous_effect') === ErrorCode::EffectUnknown);
        H::check(DatedErrorMapping::fromDated('agentools.conflict.idempotency_lost_lease') === ErrorCode::StaleClaim);
        H::check(DatedErrorMapping::fromDated('provider_timeout') === null);
        H::check(DatedErrorMapping::fromDated('agentools.unrecognized') === null);
    },
];
if (realpath($argv[0] ?? '') === __FILE__) {
    H::run($cases);
}
return $cases;
