<?php

declare(strict_types=1);

namespace AgenTools\Reference\Audit;

use AgenTools\Contracts\AuditSink;
use AgenTools\Reference\Persistence\ReferenceSliceDatabase;
use AgenTools\Reference\Persistence\RowCodec;
use DateTimeImmutable;
use PDO;

/** In-memory audit collector with optional SQLite projection (INT-002 §2, §I4). */
final class CollectingAuditSink implements AuditSink
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    public function __construct(
        private readonly string $tenantId,
        private readonly ?PDO $pdo = null,
    ) {
        if ($tenantId === '') {
            throw new \InvalidArgumentException('Audit sink tenant id must not be empty.');
        }
    }

    public static function forDatabase(ReferenceSliceDatabase $database, string $tenantId): self
    {
        return new self($tenantId, $database->pdo());
    }

    /** @param array<string, mixed> $auditEvent */
    public function export(array $auditEvent): void
    {
        $this->events[] = $auditEvent;
        if ($this->pdo === null) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO audit_events (tenant_id, event_json, exported_at) VALUES (:tenant_id, :event_json, :exported_at)',
        );
        $statement->execute([
            'tenant_id' => $this->tenantId,
            'event_json' => json_encode($auditEvent, JSON_THROW_ON_ERROR),
            'exported_at' => (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function collected(): array
    {
        return $this->events;
    }

    /** @return list<array<string, mixed>> */
    public function loadForTenant(string $tenantId): array
    {
        if ($this->pdo === null) {
            return $tenantId === $this->tenantId ? $this->events : [];
        }

        $statement = $this->pdo->prepare(
            'SELECT event_json FROM audit_events WHERE tenant_id = :tenant_id ORDER BY id ASC',
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $rows = $statement->fetchAll();
        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $decoded = json_decode(RowCodec::string($row, 'event_json'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                continue;
            }
            /** @var array<string, mixed> $decoded */
            $events[] = $decoded;
        }

        return $events;
    }
}
