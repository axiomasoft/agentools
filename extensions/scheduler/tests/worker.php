<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Conformance\WriteJourney\Journey;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Scheduler\{Scanner, Store};
use AgenToolsPlatform\Scheduler\Tests\HostAuthority;

[$script,$schema,$directory,$worker,$mode] = $argv;
$j = new Journey(new IsolatedDatabase(),$schema,'alpha','input-1');
$authority = new HostAuthority($j); $store = new Store($j->environment,$authority);
file_put_contents($directory.'/ready-'.$worker,'ready');
$deadline = microtime(true)+10;
while (!is_file($directory.'/go') && microtime(true) < $deadline) { usleep(10000); }
if (!is_file($directory.'/go')) { throw new RuntimeException('Worker barrier timeout'); }
$receipts = (new Scanner($store))->scan($j->ref('daily'));
$result = json_encode(array_map(static fn ($r): array => $r->toArray(),$receipts),JSON_THROW_ON_ERROR);
if ($mode === 'commit') {
    file_put_contents($directory.'/committed',$result);
    while (microtime(true) < $deadline+10) { usleep(10000); }
    throw new RuntimeException('Committed worker was not killed');
}
echo $result;
