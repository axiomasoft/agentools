<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Runtime\InvocationPayloads;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Runtime\RepositoryContext;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Uses the existing policy, metadata and encrypted backend; replay never extends retention. */
final readonly class InvocationPayloadStore implements InvocationPayloads
{
    public function __construct(private RepositoryContext $environment, private PayloadRepository $payloads,
        private FileEncryptedPayloadStore $store, private Ref $keyRef, private string $classification,
        private int $retentionSeconds)
    {
        if ($retentionSeconds < 1 || $retentionSeconds > 2147483647) {
            throw new InvalidArgumentException('Explicit bounded payload retention is required');
        }
    }

    public function put(ExecutionContext $context, Ref $key, PayloadHash $hash, string $json): void
    {
        $this->environment->authorize($context);
        $key->tenant->assertSame($this->keyRef->tenant);
        $hash->assertSame(new PayloadHash('sha-256:'.hash('sha256', $json)));
        $row = $this->environment->db->row('SELECT retention_until FROM '.$this->environment->db->table('payload_refs').'
            WHERE tenant_id=:tenant AND id=:id AND session_id=:session',
            ['tenant' => $key->tenant->value, 'id' => $key->value, 'session' => $context->session->value]);
        $expiry = $row === null ? $this->environment->db->row("SELECT clock_timestamp()+CAST(:seconds AS integer)*interval '1 second' AS retention_until",
            ['seconds' => $this->retentionSeconds]) : $row;
        $expires = new UtcInstant((new DateTimeImmutable(Connection::text($expiry ?? throw new RuntimeException('Payload clock unavailable'),
            'retention_until')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'));
        $handle = new PayloadHandle($key, new Ref($key->tenant, 'encrypted:'.hash('sha256',
            $context->session->value.'\0'.$key->value)), $this->keyRef);
        $this->payloads->register($context, new PayloadDescriptor($key, $hash, $this->classification, $context->purpose, $expires), $handle);
        $this->store->put($context, $handle, $json);

        if ($this->payloads->status($context, $key) !== 'available') {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }

    public function find(ExecutionContext $context, Ref $key): ?string
    {
        $this->environment->authorize($context);
        $context->session->tenant->assertSame($key->tenant);
        $row = $this->environment->db->row('SELECT id FROM '.$this->environment->db->table('payload_refs').'
            WHERE tenant_id=:tenant AND id=:id AND session_id=:session',
            ['tenant' => $key->tenant->value, 'id' => $key->value, 'session' => $context->session->value]);

        return $row === null ? null : $this->payloads->dereference($context, $key);
    }

    public function get(ExecutionContext $context, Ref $key): string
    {
        return $this->find($context, $key) ?? throw new ContractViolation(ErrorCode::CapabilityUnavailable);
    }
}
