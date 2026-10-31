<?php

declare(strict_types=1);

use AgenTools\Schema\PublishedSchemaValidator;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\SpecFixtures;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/SpecFixtures.php';
$root = dirname(__DIR__, 4);
$runner = new SpecFixtures(new PublishedSchemaValidator($root . '/spec/platform/v1/schema'));
$cases = [
    'schema-control.required-case-set' => static function (): void {
        SpecFixtures::assertCaseSet(['one', 'two'], ['two', 'one']);
        foreach ([[[], []], [['one'], []], [['one'], ['one', 'extra']], [['one', 'two'], ['one']], [['one'], ['one', 'one']]] as [$required, $actual]) {
            H::rejects(static fn () => SpecFixtures::assertCaseSet($required, $actual), RuntimeException::class);
        }
    },
    'schema-control.missing-document' => static function () use ($root): void {
        H::rejects(static fn () => SpecFixtures::cases($root . '/fixtures/platform/v1/contracts/not-a-repository'), RuntimeException::class);
    },
    'schema-control.actual-schema' => static function () use ($runner, $root): void {
        $fixture = SpecFixtures::document($root . '/fixtures/platform/v1/contracts/valid/01-trusted-input.json');
        $runner->assertFixture($fixture);
        $fixture['data'] = ['authorized' => true];
        H::rejects(static fn () => $runner->assertFixture($fixture), RuntimeException::class);
    },
    'schema-control.rejection-reason' => static function () use ($runner, $root): void {
        $fixture = SpecFixtures::document($root . '/fixtures/platform/v1/contracts/rejected/26-unknown-as-known.json');
        $runner->assertFixture($fixture);
        $fixture['expect'] = ['valid' => false, 'reason_code' => 'access_denied'];
        H::rejects(static fn () => $runner->assertFixture($fixture), RuntimeException::class);
    },
    'schema-control.declared-partitions' => static function () use ($root): void {
        $cases = SpecFixtures::cases($root);
        H::check(count($cases) === 40 && isset($cases['schema.01-trusted-input']) && isset($cases['schema.24-tenant-mismatch']));
    },
];
if (realpath($argv[0] ?? '') === __FILE__) {
    H::run($cases);
}
return $cases;
