<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\RepositoryContext;

/** Explicit retention bounds decorate, and never replace, the host's current source/data authority. */
final readonly class BoundedPayloadPolicy implements PayloadPolicy
{
    /** @param array<string,array<string,int>> $seconds classification => purpose => maximum TTL; no defaults. */
    public function __construct(private RepositoryContext $environment, private PayloadPolicy $sourcePolicy, private array $seconds)
    {
        foreach ($seconds as $purposes) {
            foreach ($purposes as $maximum) {
                self::validateSeconds($maximum);
            }
        }
    }

    public function authorize(ExecutionContext $context, PayloadDescriptor $payload, string $operation): Ref
    {
        $this->environment->authorize($context);
        $context->session->tenant->assertSame($payload->ref->tenant);
        $maximum = $this->seconds[$payload->classification][$payload->purpose] ?? null;
        if ($maximum === null || $context->purpose !== $payload->purpose
            || !in_array($operation, ['register', 'status', 'materialize', 'dereference', 'purge'], true)) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        if ($operation === 'register') {
            // The database clock owns expiry, including across worker restarts.
            $row = $this->environment->db->row('SELECT CAST(:expiry AS timestamptz)>clock_timestamp()
                AND CAST(:upper AS timestamptz)<=clock_timestamp()+CAST(:seconds AS integer)*interval \'1 second\' AS allowed',
                ['expiry' => $payload->retentionUntil->value, 'upper' => $payload->retentionUntil->value, 'seconds' => $maximum]);
            if ($row === null || $row['allowed'] !== true) {
                throw new ContractViolation(ErrorCode::AccessDenied);
            }
        }
        return $this->sourcePolicy->authorize($context, $payload, $operation);
    }

    /** Validate the runtime value too: PHP cannot enforce nested array element types. */
    private static function validateSeconds(mixed $maximum): void
    {
        if (!is_int($maximum) || $maximum < 1 || $maximum > 2147483647) {
            throw new \InvalidArgumentException('An explicit positive PostgreSQL integer TTL is required');
        }
    }
}
