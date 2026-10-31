<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapterTests;

use AgenToolsPlatform\Core\Common\{ProposalBinding, IntegrityConflict};

/** Test-only committed SQLite mapping/intent. Not an AgenTools production Action owner. */
final class MappingHarness
{
    private \PDO $db;
    public function __construct(private readonly string $file)
    {
        $this->db = new \PDO('sqlite:' . $file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE IF NOT EXISTS mappings (tenant TEXT, proposal TEXT, action TEXT, hash TEXT, state TEXT NOT NULL, result TEXT, PRIMARY KEY(tenant,proposal))');
    }
    public function save(ProposalBinding $mapping): void
    {
        $row = $this->row($mapping);
        if ($row !== null) { $this->same($mapping, $row); return; }
        $stmt = $this->db->prepare("INSERT INTO mappings VALUES (?,?,?,?,'mapped',NULL)");
        $stmt->execute([$mapping->proposalId->tenant->value, $mapping->proposalId->value, $mapping->actionId->value, $mapping->inputHash->value]);
    }
    /** @param \Closure(): string $callback */
    public function dispatch(ProposalBinding $mapping, \Closure $callback, bool $loseReceipt = false): string
    {
        $row = $this->row($mapping);
        if ($row === null) { throw new \RuntimeException('Mapping must be committed before dispatch'); }
        $this->same($mapping, $row);
        if ($row['state'] === 'known') { return self::text($row, 'result'); }
        if ($row['state'] !== 'mapped') { throw new \RuntimeException('Unknown effect requires owner reconciliation'); }
        $this->db->beginTransaction();
        $stmt = $this->db->prepare("UPDATE mappings SET state='unknown' WHERE tenant=? AND proposal=? AND state='mapped'");
        $stmt->execute([$mapping->proposalId->tenant->value, $mapping->proposalId->value]);
        if ($stmt->rowCount() !== 1) { $this->db->rollBack(); throw new \RuntimeException('Dispatch lost claim'); }
        $this->db->commit();
        $result = $callback();
        if ($loseReceipt) { throw new \RuntimeException('Injected lost receipt'); }
        $stmt = $this->db->prepare("UPDATE mappings SET state='known',result=? WHERE tenant=? AND proposal=?");
        $stmt->execute([$result, $mapping->proposalId->tenant->value, $mapping->proposalId->value]);
        return $result;
    }
    /** @return array<string, mixed>|null */
    private function row(ProposalBinding $mapping): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mappings WHERE tenant=? AND proposal=?');
        $stmt->execute([$mapping->proposalId->tenant->value, $mapping->proposalId->value]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) { return null; }
        return $row;
    }
    /** @param array<string, mixed> $row */
    private function same(ProposalBinding $mapping, array $row): void
    {
        if ($row['action'] !== $mapping->actionId->value || $row['hash'] !== $mapping->inputHash->value) { throw new IntegrityConflict('Saved proposal mapping conflict'); }
    }
    /** @param array<string, mixed> $row */
    private static function text(array $row, string $key): string
    {
        if (!is_string($row[$key])) { throw new \RuntimeException('Invalid result'); }
        return $row[$key];
    }
}
