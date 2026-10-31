<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use AgenTools\Action\VersionConflict;
use AgenToolsPlatform\Postgres\Tests\Actions\Fixture;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

$database = new IsolatedDatabase();
$fixture = new Fixture($database, $argv[1], false);
$mode = $argv[2];
$statement = $database->pdo->prepare("SELECT set_config('application_name',:name,false)");
$statement->execute(['name' => 'journal_' . $fixture->schema . '_' . $mode]);
echo "ready\n";
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'go') {
    exit(2);
}
try {
    $events = $mode === 'crash' ? $fixture->events() : [$fixture->event('race-' . $mode, 'action_started')];
    $projection = $fixture->actions->append($fixture->context, $fixture->runtime->snapshot($fixture->context),
        $fixture->claim, $fixture->action, $mode === 'crash' ? 0 : 1, $events, $mode === 'crash' ? [$fixture->intent()] : []);
    if ($mode === 'crash') {
        exit(73); // SQL commit happened; the owner was never called.
    }
    echo $projection->digest() . "\n";
} catch (VersionConflict) {
    exit(8);
}
