<?php

declare(strict_types=1);

namespace AgenTools\Reference\Persistence;

use AgenTools\Action\ActionStatus;
use AgenTools\Action\SessionStateProjection;
use AgenTools\Contracts\SessionStore;
use AgenTools\Reference\Persistence\RowCodec;
use PDO;

/** SQLite projection cache separate from the append-only journal. */
final class SqliteSessionStore implements SessionStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function load(string $actionId): ?SessionStateProjection
    {
        $statement = $this->pdo->prepare('SELECT * FROM session_projections WHERE action_id = :action_id LIMIT 1');
        $statement->execute(['action_id' => $actionId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new SessionStateProjection(
            actionId: RowCodec::string($row, 'action_id'),
            status: ActionStatus::from(RowCodec::string($row, 'status')),
            seq: RowCodec::int($row, 'seq'),
            specRevision: RowCodec::string($row, 'spec_rev'),
            epoch: RowCodec::int($row, 'epoch'),
            projectedAt: isset($row['projected_at']) && is_string($row['projected_at']) ? $row['projected_at'] : null,
        );
    }

    public function save(SessionStateProjection $projection): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO session_projections (action_id, status, seq, spec_rev, epoch, projected_at)
             VALUES (:action_id, :status, :seq, :spec_rev, :epoch, :projected_at)
             ON CONFLICT(action_id) DO UPDATE SET
                status = excluded.status,
                seq = excluded.seq,
                spec_rev = excluded.spec_rev,
                epoch = excluded.epoch,
                projected_at = excluded.projected_at',
        );
        $statement->execute([
            'action_id' => $projection->actionId,
            'status' => $projection->status->value,
            'seq' => $projection->seq,
            'spec_rev' => $projection->specRevision,
            'epoch' => $projection->epoch,
            'projected_at' => $projection->projectedAt,
        ]);
    }
}
