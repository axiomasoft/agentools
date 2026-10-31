<?php

declare(strict_types=1);

use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\JcsAdapter;

require_once __DIR__ . '/bootstrap.php';
$json = new JcsAdapter();
$hash = new CanonicalHasher($json);
$tenant = new TenantId('tenant-a');
$cases = [
    'jcs.existing-encoder-vectors' => static function () use ($json): void {
        H::check($json->canonicalizeJson('{"z":-0.0,"a":1e-7,"b":1e20}') === '{"a":1e-7,"b":100000000000000000000,"z":0}');
        H::check($json->canonicalizeJson('{"\u20ac":1,"a":2}') === '{"a":2,"€":1}');
    },
    'jcs.duplicate-and-object-array' => static function () use ($json): void {
        H::rejects(static fn () => $json->canonicalizeJson('{"a":1,"a":2}'), \AgenTools\Schema\CanonicalizationException::class);
        H::check($json->canonicalizeJson('{}') !== $json->canonicalizeJson('[]'));
    },
    'jcs.order-invariance' => static function () use ($hash, $tenant): void {
        H::check($hash->hash($tenant, 'request', ['a' => 1, 'b' => 2])->value === $hash->hash($tenant, 'request', ['b' => 2, 'a' => 1])->value);
    },
    'jcs.scope-domain-separation' => static function () use ($hash, $tenant): void {
        $one = $hash->hash($tenant, 'request', ['x' => 1]);
        H::check($one->value !== $hash->hash(new TenantId('tenant-b'), 'request', ['x' => 1])->value);
        H::check($one->value !== $hash->hash($tenant, 'response', ['x' => 1])->value);
        H::rejects(static fn () => $hash->hash($tenant, '', []), InvalidArgumentException::class);
    },
    'jcs.unsafe-number-rejected' => static function () use ($hash, $tenant): void {
        foreach ([9007199254740992, ['nested' => 9007199254740993], INF, NAN] as $bad) {
            H::rejects(static fn () => $hash->hash($tenant, 'request', $bad), InvalidArgumentException::class);
        }
        H::check($hash->hash($tenant, 'request', (object) ['a' => 1])->value === $hash->hash($tenant, 'request', ['a' => 1])->value);
    },
];
if (realpath($argv[0] ?? '') === __FILE__) {
    H::run($cases);
}
return $cases;
