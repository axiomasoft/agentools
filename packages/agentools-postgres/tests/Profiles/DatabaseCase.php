<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Profiles;

use AgenToolsPlatform\Postgres\Profiles\Schema;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

abstract class DatabaseCase extends TestCase
{
    protected IsolatedDatabase $database;
    protected string $schema;
    protected Fixture $f;

    protected function setUp(): void
    {
        $this->database = new IsolatedDatabase(); $this->schema = 'p3_' . bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema);
        Schema::install($this->database->pdo, $this->schema);
        $this->f = new Fixture($this->database->pdo, $this->schema);
    }
    protected function tearDown(): void
    {
        if (isset($this->database, $this->schema)) {
            if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); }
            $this->database->dropSchema($this->schema);
        }
    }
    protected function scalar(string $sql): mixed
    {
        $statement = $this->database->pdo->query($sql);
        self::assertNotFalse($statement);
        return $statement->fetchColumn();
    }
    /**
     * @param callable(): mixed $operation
     * @param class-string<\Throwable> $class
     */
    protected function rejects(callable $operation, string $class): void
    {
        try { $operation(); }
        catch (\Throwable $error) { self::assertInstanceOf($class, $error, $error->getMessage()); return; }
        self::fail('Expected rejection: ' . $class);
    }
}
