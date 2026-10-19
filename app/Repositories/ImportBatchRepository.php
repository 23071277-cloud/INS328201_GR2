<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/** FN-04.03 / 04.05 - import_batches (+ import_errors). */
class ImportBatchRepository extends BaseRepository
{
    protected string $table = 'import_batches';

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ib.*, p.period_code, p.status AS period_status, u.full_name AS uploaded_by_name,
                    (SELECT COUNT(*) FROM meter_readings r WHERE r.import_batch_id = ib.id) AS saved_readings
             FROM import_batches ib JOIN billing_periods p ON p.id = ib.billing_period_id
             LEFT JOIN users u ON u.id = ib.uploaded_by WHERE ib.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function listByPeriod(int $periodId): array
    {
        return $this->fetchAllQuery(
            'SELECT ib.*, u.full_name AS uploaded_by_name,
                    (SELECT COUNT(*) FROM meter_readings r WHERE r.import_batch_id = ib.id) AS saved_readings
             FROM import_batches ib LEFT JOIN users u ON u.id = ib.uploaded_by
             WHERE ib.billing_period_id = :p ORDER BY ib.created_at DESC, ib.id DESC',
            ['p' => $periodId]
        );
    }

    /** @param array<int, array{row_no:int,apartment_code:?string,error_message:string}> $errors */
    public function replaceErrors(int $batchId, array $errors): void
    {
        $this->db->prepare('DELETE FROM import_errors WHERE import_batch_id = :b')->execute(['b' => $batchId]);
        $stmt = $this->db->prepare('INSERT INTO import_errors (import_batch_id, row_no, apartment_code, error_message) VALUES (:b, :r, :a, :m)');
        foreach ($errors as $error) {
            $stmt->execute(['b' => $batchId, 'r' => $error['row_no'], 'a' => $error['apartment_code'], 'm' => mb_substr($error['error_message'], 0, 500)]);
        }
    }

    public function errorsOf(int $batchId): array
    {
        return $this->fetchAllQuery('SELECT * FROM import_errors WHERE import_batch_id = :b ORDER BY row_no, id', ['b' => $batchId]);
    }
}
