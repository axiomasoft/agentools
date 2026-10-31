<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Actions;

use AgenTools\Action\Actor;
use AgenTools\Persistence\UncommittedEvent;
use AgenToolsPlatform\Core\Common\{ActionId, PayloadHash, Ref, TenantId};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Actions\{ActionRepository, EffectIntent};
use AgenToolsPlatform\Postgres\Claims\{ClaimRepository, RunClaim};
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RunRepository, SessionRepository};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Tests\Runtime\Fixture as RuntimeFixture;

final readonly class Fixture
{
    public RuntimeFixture $runtime;
    public ExecutionContext $context;
    public ActionId $action;
    public RunClaim $claim;
    public ActionRepository $actions;

    public function __construct(public IsolatedDatabase $database, public string $schema, bool $seed = true)
    {
        $this->runtime = new RuntimeFixture($database->pdo, $schema, new TenantId('tenant-a'));
        $this->context = $this->runtime->context();
        $this->action = new ActionId($this->runtime->tenant, 'action:one');
        if ($seed) {
            (new SessionRepository($this->runtime->environment))->bind($this->context, $this->runtime->binding($this->context));
            $receipt = (new IntakeRepository($this->runtime->environment))->accept($this->runtime->input($this->context), $this->runtime->snapshot($this->context));
            $this->claim = (new ClaimRepository($this->runtime->environment))->claim($this->context,
                $this->runtime->snapshot($this->context), $receipt->runId, 0) ?? throw new \RuntimeException('No claim');
            $db = $this->runtime->environment->db;
            $db->execute('INSERT INTO ' . $db->table('run_steps') . '(tenant_id,id,run_id,step_key,action_ref)
                VALUES (\'tenant-a\',\'step\',:run,\'step\',:action)', ['run' => $receipt->runId->value, 'action' => $this->action->value]);
            $db->execute('INSERT INTO ' . $db->table('invocation_intents') . '
                (tenant_id,id,run_id,step_key,invocation_key,request_hash,request_ref,model_ref,admission_ref)
                VALUES (\'tenant-a\',\'invocation\',:run,\'step\',\'invocation\',:hash,\'encrypted:invocation\',\'model:ref\',\'admission:ref\')', [
                    'run' => $receipt->runId->value, 'hash' => str_repeat('a', 64)]);
            $db->execute('INSERT INTO ' . $db->table('proposal_map') . '
                (tenant_id,id,run_id,invocation_id,proposal_key,payload_hash,proposal_ref,action_ref)
                VALUES (\'tenant-a\',\'proposal\',:run,\'invocation\',\'proposal\',:hash,\'encrypted:proposal\',:action)', [
                    'run' => $receipt->runId->value, 'hash' => str_repeat('a', 64), 'action' => $this->action->value]);
        } else {
            $db = $this->runtime->environment->db;
            $row = $db->row('SELECT id,claim_owner,lease_until FROM ' . $db->table('runs') . ' WHERE tenant_id=\'tenant-a\'')
                ?? throw new \RuntimeException('No durable Run');
            $run = (new RunRepository($this->runtime->environment))->get($this->context,
                new \AgenToolsPlatform\Core\Common\RunId($this->runtime->tenant, Connection::text($row, 'id')))
                ?? throw new \RuntimeException('No durable Run');
            $this->claim = new RunClaim($run, Connection::text($row, 'claim_owner'), Connection::text($row, 'lease_until'));
        }
        $this->actions = new ActionRepository($this->runtime->environment);
    }

    public function intent(): EffectIntent
    {
        return new EffectIntent('effect:one', 'test-owner', 'owner:operation:one',
            new PayloadHash('sha-256:' . str_repeat('b', 64)), new Ref($this->runtime->tenant, 'encrypted:request:one'));
    }

    /** @return list<UncommittedEvent> */
    public function events(): array
    {
        $path = dirname(__DIR__, 4) . '/fixtures/platform/v1/journal/atomic-intent.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Required journal fixture missing');
        }
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['events'] ?? null)) {
            throw new \RuntimeException('Invalid journal fixture');
        }
        $events = [];
        foreach ($data['events'] as $event) {
            if (!is_array($event) || !is_string($event['event_id'] ?? null) || !is_string($event['kind'] ?? null)
                || !is_array($event['payload'] ?? null) || (isset($event['epoch']) && !is_int($event['epoch']))) {
                throw new \RuntimeException('Invalid fixture event');
            }
            $payload = [];
            foreach ($event['payload'] as $key => $value) {
                if (!is_string($key)) {
                    throw new \RuntimeException('Named payload required');
                }
                $payload[$key] = $value;
            }
            $events[] = $this->event($event['event_id'], $event['kind'], $payload, $event['epoch'] ?? null);
        }
        return $events;
    }

    /** @param array<string, mixed> $payload */
    public function event(string $id, string $kind, array $payload = [], ?int $epoch = null): UncommittedEvent
    {
        return new UncommittedEvent($id, $kind, 1, '2026-09', new Actor('user', 'actor'),
            '2026-10-31T14:00:00.1234567+03:00', $payload, $epoch);
    }
}
