<?php

declare(strict_types=1);

namespace AgenTools\Reference\Tools;

/** In-memory day-report domain state for the reference product slice. */
final class DayReportStore
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $activitiesByTenantDate = [];

    /** @var array<string, array<string, mixed>> */
    private array $reportsByTenant = [];

    /**
     * @param list<array<string, mixed>> $activities
     */
    public function seedActivities(string $tenantId, string $date, array $activities): void
    {
        $this->activitiesByTenantDate[$this->key($tenantId, $date)] = $activities;
    }

    /** @return list<array<string, mixed>> */
    public function activities(string $tenantId, string $date): array
    {
        return $this->activitiesByTenantDate[$this->key($tenantId, $date)] ?? [];
    }

    /**
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    public function saveDraft(string $tenantId, array $draft): array
    {
        $draftId = 'draft-' . hash('sha256', $tenantId . json_encode($draft, JSON_THROW_ON_ERROR));
        $record = ['draft_id' => $draftId] + $draft;
        $this->reportsByTenant[$tenantId . ':' . $draftId] = $record;

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function commitReport(string $tenantId, string $reportId, string $body, string $date): array
    {
        $record = [
            'report_id' => $reportId,
            'tenant_id' => $tenantId,
            'date' => $date,
            'body' => $body,
            'committed_at' => 'committed',
        ];
        $this->reportsByTenant[$tenantId . ':' . $reportId] = $record;

        return $record;
    }

    public function hasReport(string $tenantId, string $reportId): bool
    {
        return isset($this->reportsByTenant[$tenantId . ':' . $reportId]);
    }

    private function key(string $tenantId, string $date): string
    {
        return $tenantId . ':' . $date;
    }
}
