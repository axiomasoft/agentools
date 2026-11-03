<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\EdufyHost;

use AgenToolsPlatform\Core\Common\{AcceptedTurn, CanonicalHasher, IdentityKeys, IntegrityConflict};
use AgenToolsPlatform\Laravel\Host\{Boundary, Envelope};

/** Single-process contract stub. Production intake/outbox belongs to existing PG repositories. */
final class IntakeStub
{
    /** @var array<string, AcceptedTurn> */
    private array $turns = [];
    public function __construct(private Boundary $boundary, private CanonicalHasher $hasher) {}
    public function accept(Envelope $envelope): AcceptedTurn
    {
        $this->boundary->assertCurrent($envelope);
        $source = $envelope->input->source;
        $key = $source->key($this->hasher); $hash = $source->payloadHash($envelope->input->payload, $this->hasher);
        if (isset($this->turns[$key->value])) {
            $old = $this->turns[$key->value];
            if ($old->payloadHash->value !== $hash->value) { throw new IntegrityConflict('SOURCE_PAYLOAD_CONFLICT'); }
            return $old->replay($source, $hash, $this->hasher);
        }
        $keys = new IdentityKeys($this->hasher); $turn = $keys->turn($source);
        return $this->turns[$key->value] = new AcceptedTurn($key, $hash, $turn, $keys->run($turn));
    }
    public function count(): int { return count($this->turns); }
    /** @return list<array<string, mixed>> */
    public function technicalRows(): array { return array_map(static fn (AcceptedTurn $ack): array => $ack->toArray(), array_values($this->turns)); }
}
