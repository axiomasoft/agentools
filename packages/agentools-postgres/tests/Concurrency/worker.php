<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use AgenToolsPlatform\Core\Common\{ContractViolation, RunStatus, TenantId};
use AgenToolsPlatform\Postgres\Claims\{ClaimPolicy, ClaimRepository, Scanner};
use AgenToolsPlatform\Postgres\Outbox\OutboxRepository;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Tests\Runtime\Fixture;

$database = new IsolatedDatabase();
$fixture = new Fixture($database->pdo, $argv[1], new TenantId('tenant-a'));
$context = $fixture->context();
$snapshot = $fixture->snapshot($context);
$mode = $argv[2];
if ($mode === 'relay-crash') {
    $outbox = new OutboxRepository($fixture->environment, 100);
    $claim = $outbox->claim($context) ?? throw new RuntimeException('No wake');
    // A transport accepted the event; die before persisting the receipt.
    echo json_encode(['event_key' => $claim->signal->eventKey, 'until' => $claim->claimedUntil], JSON_THROW_ON_ERROR) . "\n";
    exit(73);
}
$candidate = (new Scanner($fixture->environment))->scan($context)[0];
$claims = new ClaimRepository($fixture->environment, new ClaimPolicy($mode === 'stale' ? 300 : 10000));
$claim = $claims->claim($context, $snapshot, $candidate['run'], $candidate['version']) ?? throw new RuntimeException('No claim');
if ($mode === 'stale') {
    $claim = $claims->checkpoint($context, $snapshot, $claim, RunStatus::Preparing,
        ['owner_operation_key' => 'owner:immutable', 'outcome' => 'unknown']);
    echo json_encode(['epoch' => $claim->state->fencingEpoch, 'until' => $claim->leaseUntil,
        'io_outside_tx' => !$database->pdo->inTransaction()], JSON_THROW_ON_ERROR) . "\n";
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'go') {
        exit(2);
    }
    try {
        $claims->checkpoint($context, $snapshot, $claim, RunStatus::Running, ['corrupt' => true]);
        exit(3);
    } catch (ContractViolation $error) {
        echo $error->errorCode->value . "\n";
    }
} else {
    $saved = $claim->state->checkpoint['progress'];
    if (!is_array($saved) || !is_string($saved['owner_operation_key'] ?? null)) {
        throw new RuntimeException('Saved owner operation identity missing');
    }
    // Recovery classifies unknown; it does not call the owner again.
    $claim = $claims->checkpoint($context, $snapshot, $claim, RunStatus::Running, $saved);
    echo json_encode(['epoch' => $claim->state->fencingEpoch, 'version' => $claim->state->version,
        'owner_operation_key' => $saved['owner_operation_key'], 'outcome' => $saved['outcome']], JSON_THROW_ON_ERROR) . "\n";
}
