<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Runtime;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict};
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/** Explicit schema qualification; no dependence on a pooled connection's search_path. */
final readonly class Connection
{
    public function __construct(private PDO $pdo, private string $schema)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $schema) !== 1
            || in_array($schema, ['public', 'information_schema'], true) || str_starts_with($schema, 'pg_')) {
            throw new \InvalidArgumentException('Explicit PostgreSQL application schema required');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function table(string $name): string
    {
        if (preg_match('/\A[a-z_]+\z/D', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid table identifier');
        }
        return '"' . $this->schema . '"."' . $name . '"';
    }

    public function assertIdle(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Repository owns its transaction; caller transactions are refused');
        }
    }

    /** Local participants must share an explicit repository-owned transaction. */
    public function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Accounting participant requires a transaction'); }
    }

    /** A participant on exactly this connection/schema; it never opens a nested transaction. */
    public function journal(): \AgenTools\Persistence\PdoPostgresEventStore
    {
        return new \AgenTools\Persistence\PdoPostgresEventStore($this->pdo, schema: $this->schema);
    }

    /** Explicit worker-owned lifecycle only: clear transaction, role, settings and session resources. */
    public function resetSession(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->exec('DISCARD ALL');
        } catch (PDOException) {
            throw new StorageFailure();
        }
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        $this->assertIdle();
        try {
            $this->pdo->beginTransaction();
            // A blocked duplicate INSERT must see its winner in the following SELECT.
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (PDOException) {
                    throw new StorageFailure();
                }
            }
            if ($error instanceof PDOException) {
                self::translate($error);
            }
            throw $error;
        }
    }

    /** @param array<string, scalar|null> $parameters */
    public function execute(string $sql, array $parameters = []): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            return $statement;
        } catch (PDOException $error) {
            self::translate($error);
        }
    }

    /**
     * @param array<string, scalar|null> $parameters
     * @return array<string, mixed>|null
     */
    public function row(string $sql, array $parameters = []): ?array
    {
        try {
            $row = $this->execute($sql, $parameters)->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }
            return $row;
        } catch (PDOException $error) {
            self::translate($error);
        }
    }

    /** @param array<string, mixed> $row */
    public static function text(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new StorageFailure();
        }
        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    public static function number(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value) && $value >= 0 && $value <= 9007199254740991) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && strlen($value) <= 16
            && (int) $value <= 9007199254740991) {
            return (int) $value;
        }
        throw new StorageFailure();
    }

    private static function translate(PDOException $error): never
    {
        $state = (string) $error->getCode();
        if ($state === '23505') {
            throw new IntegrityConflict('Accepted persistence identity conflict');
        }
        if (in_array($state, ['40001', '40P01', '55P03'], true)) {
            throw new ContractViolation(ErrorCode::OptimisticConflict);
        }
        if (str_starts_with($state, '22') || in_array($state, ['23502', '23503', '23514'], true)) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        // Neither raw SQL nor database credentials escape through a previous exception.
        throw new StorageFailure();
    }
}
