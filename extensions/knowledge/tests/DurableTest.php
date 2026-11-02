<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests;

use AgenToolsPlatform\Knowledge\{Decision, Scope};
use AgenToolsPlatform\Knowledge\Adapters\Postgres\Repository;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Process.php';

final class DurableTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    private Fixture $f;
    private Repository $repository;

    protected function setUp(): void
    {
        $this->database = new IsolatedDatabase();
        $this->schema = 'p3_' . bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema);
        $this->f = new Fixture(); $this->f->owner->pdo = $this->database->pdo;
        $this->repository = new Repository($this->database->pdo, $this->schema, $this->f->owner);
        $this->repository->install();
    }
    protected function tearDown(): void { $this->database->dropSchema($this->schema); }
    private function rowCount(string $table = 'knowledge_releases'): int
    { return (int)$this->database->pdo->query('SELECT count(*) FROM "' . $this->schema . '".' . $table)->fetchColumn(); }
    private function publish(string $id = 'release:1')
    { return $this->f->pipeline($this->repository)->ingest($this->f->scope, 'corpus:school', $id, $this->f->material); }
    private function denied(\Closure $call): void
    { try { $call(); self::fail('Expected restriction'); } catch (\DomainException) { self::assertTrue(true); } }

    public function testDurableReplayAndHashConflict(): void
    {
        $release = $this->publish(); self::assertSame($release->hash(), $this->publish()->hash());
        $restarted = new Repository((new IsolatedDatabase())->pdo, $this->schema, $this->f->owner);
        self::assertSame($release->hash(), $restarted->get($this->f->scope, 'corpus:school', 'release:1')->hash());
        $this->denied(fn() => $this->repository->publish($this->f->scope,
            \AgenToolsPlatform\Knowledge\Release::restore($release->json(), $release->hash())));
        $other = new Fixture(version: 'v2');
        $this->denied(fn() => $other->pipeline($this->repository)->ingest($other->scope, 'corpus:school', 'release:1', $other->material));
        $changed = new Fixture(text: 'Different bytes under same source version');
        $this->denied(fn() => $changed->pipeline($this->repository)->ingest($changed->scope, 'corpus:school', 'release:2', $changed->material));
        self::assertSame(1, $this->rowCount());
    }

    public function testCorrectionInvalidatesExactDependents(): void
    {
        $release = $this->publish();
        foreach (['bundle', 'snapshot', 'cache'] as $kind) {
            $this->repository->link($this->f->scope, $release, $kind, $kind . ':1');
            self::assertTrue($this->repository->dependencyUsable($this->f->scope, $release, $kind, $kind . ':1'));
        }
        self::assertFalse($this->repository->dependencyUsable($this->f->scope,
            (new Fixture(text: 'Forged descriptor'))->release(), 'cache', 'cache:1'));
        $next = new Fixture(version: 'v2', text: 'Corrected text');
        $new = $next->pipeline($this->repository)->ingest($next->scope, 'corpus:school', 'release:2', $next->material, previous: $this->f->material);
        self::assertSame($new->hash(), $this->repository->get($next->scope, 'corpus:school', 'release:2')->hash());
        foreach (['bundle', 'snapshot', 'cache'] as $kind) {
            self::assertFalse($this->repository->dependencyUsable($this->f->scope, $release, $kind, $kind . ':1'));
        }
        $this->denied(fn() => $this->repository->get($this->f->scope, 'corpus:school', 'release:1'));
        $this->denied(fn() => $this->repository->link($this->f->scope, $release, 'cache', 'late:cache'));
        $this->denied(fn() => $this->publish());
        $row = $this->database->pdo->query('SELECT payload,content_hash FROM knowledge_releases WHERE release_id=\'release:1\'')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($release->json(), $row['payload']); self::assertSame($release->hash(), $row['content_hash']);
        self::assertSame(2, $this->rowCount());
    }

    public function testFailedCorrectionRollsBack(): void
    {
        $old = $this->publish(); $next = new Fixture(version: 'v2');
        $this->denied(fn() => $next->pipeline($this->repository)->ingest($next->scope, 'corpus:school', 'release:1', $next->material, previous: $this->f->material));
        self::assertSame(0, $this->rowCount('knowledge_invalidations'));
        self::assertSame($old->hash(), $this->repository->get($this->f->scope, 'corpus:school', 'release:1')->hash());
        self::assertSame(1, $this->rowCount());
    }

    public function testTenantCurrentRightsAndUnknown(): void
    {
        $release = $this->publish();
        $other = new Fixture('tenant:b');
        self::assertNull($this->repository->get($other->scope, 'corpus:school', 'release:1'));
        $this->denied(fn() => $this->repository->publish($other->scope, $release));
        $this->denied(fn() => $this->repository->invalidate($other->scope, $release->material, 'revoke'));
        foreach ([new Scope('tenant:a', 'actor:a', 'staff', 'education', ['read']),
            new Scope('tenant:a', 'actor:a', 'students', 'education', [])] as $scope) {
            $this->denied(fn() => $this->repository->get($scope, 'corpus:school', 'release:1'));
        }
        foreach ([Decision::Denied, Decision::Unknown] as $decision) {
            $this->f->owner->decision = $decision;
            $this->denied(fn() => $this->repository->get($this->f->scope, 'corpus:school', 'release:1'));
        }
        $this->f->owner->decision = Decision::Allowed;
        $this->repository->invalidate($this->f->scope, $release->material, 'owner-revoked');
        $this->repository->invalidate($this->f->scope, $release->material, 'owner-revoked');
        $this->denied(fn() => $this->repository->get($this->f->scope, 'corpus:school', 'release:1'));
        self::assertSame(1, $this->rowCount('knowledge_invalidations'));
    }

    public function testSqlImmutabilityAndTenantForeignKey(): void
    {
        $this->publish(); $this->repository->install();
        foreach (["UPDATE knowledge_releases SET payload='{}'", 'DELETE FROM knowledge_releases',
            "INSERT INTO knowledge_dependencies VALUES ('tenant:b','corpus:school','release:1','cache','cache:bad')"] as $sql) {
            try { $this->database->pdo->exec($sql); self::fail('SQL invariant bypassed'); }
            catch (\PDOException $error) { self::assertContains($error->getCode(), ['23514', '23503']); }
        }
        self::assertSame(1, $this->rowCount()); self::assertSame(0, $this->rowCount('knowledge_dependencies'));
    }

    public function testCompetingConnections(): void
    {
        foreach (['replay', 'conflict'] as $mode) {
            $id = $mode === 'replay' ? 'release:1' : 'release:race';
            $a = new Process('publish', $this->schema, $id, 'v1');
            $b = new Process('publish', $this->schema, $id, $mode === 'replay' ? 'v1' : 'v2');
            self::assertSame('READY', $a->line()); self::assertSame('READY', $b->line());
            $a->go(); $b->go();
            $left = json_decode($a->line(), true, flags: JSON_THROW_ON_ERROR);
            $right = json_decode($b->line(), true, flags: JSON_THROW_ON_ERROR);
            self::assertNotSame($left['pid'], $right['pid']);
            self::assertSame(0, $a->finish()); self::assertSame(0, $b->finish());
            if ($mode === 'replay') {
                self::assertSame('published', $left['status']); self::assertSame('published', $right['status']);
                self::assertSame($left['hash'], $right['hash']);
            } else {
                $statuses = [$left['status'], $right['status']]; sort($statuses);
                self::assertSame(['conflict', 'published'], $statuses);
            }
        }
        self::assertSame(2, $this->rowCount());
    }

    public function testCrashBeforeAndAfterCommitRestart(): void
    {
        foreach (['crash-before', 'crash-after'] as $mode) {
            $id = $mode;
            $worker = new Process($mode, $this->schema, $id);
            self::assertSame('READY', $worker->line()); $worker->go(); $line = $worker->line();
            if ($mode === 'crash-before') { self::assertSame('PRECOMMIT', $line); }
            else { self::assertSame('published', json_decode($line, true, flags: JSON_THROW_ON_ERROR)['status']); }
            $status = $worker->kill(); self::assertTrue($status['signaled']); self::assertSame(9, $status['termsig']);
            $retry = new Process('publish', $this->schema, $id);
            self::assertSame('READY', $retry->line()); $retry->go();
            $result = json_decode($retry->line(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('published', $result['status']); self::assertSame(0, $retry->finish());
            self::assertSame($this->f->release($id)->hash(), $result['hash']);
        }
        self::assertSame(2, $this->rowCount());
    }
}
