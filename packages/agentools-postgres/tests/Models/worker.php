<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Tests\Models\TestRig;
$database = new IsolatedDatabase();
$rig = new TestRig($database->pdo, $argv[1] ?? throw new RuntimeException('Schema required'));
$target = $argv[2] ?? '';
$rig->provider->tools = $target !== 'complete';
$rig->barrier = static function (string $boundary) use ($target): void {
    if ($boundary === $target) {
        fwrite(STDOUT, "boundary:" . $boundary . "\n"); fflush(STDOUT);
        // Parent kills this process after observing the durable boundary.
        $deadline = hrtime(true) + 30_000_000_000;
        while (hrtime(true) < $deadline) { usleep(10000); }
        throw new RuntimeException('Parent did not kill the worker');
    }
};
$saved = $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
$rig->boundary('complete');
echo json_encode($saved->data, JSON_THROW_ON_ERROR);
