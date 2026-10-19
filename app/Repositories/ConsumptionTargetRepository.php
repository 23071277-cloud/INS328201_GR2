<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use PDOException;

/** FN-06.03 - consumption_targets (UNIQUE scope_type + scope_id + utility_type + billing_period_id). */
class ConsumptionTargetRepository extends BaseRepository
{
    protected string $table = 'consumption_targets';

    private const SELECT = 'SELECT t.*, p.period_code, p.status AS period_status, p.start_date, p.end_date, u.full_name AS created_by_name
        FROM consumption_targets t JOIN billing_periods p ON p.id = t.billing_period_id LEFT JOIN users u ON u.id = t.created_by';

    /** @param array{period_id:string,utility_type:string,scope_type:string} $filters */
    public function search(array $filters): array
    {
        $where = [];
        $params = [];
        if ($filters['period_id'] !== '') {
            $where[] = 't.billing_period_id = :period_id';
            $params['period_id'] = (int) $filters['period_id'];
        }
        foreach (['utility_type', 'scope_type'] as $key) {
            if ($filters[$key] !== '') {
                $where[] = "t.{$key} = :{$key}";
                $params[$key] = $filters[$key];
            }
        }

        return $this->fetchAllQuery(self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY p.start_date DESC, t.scope_type, t.scope_id, t.utility_type LIMIT 1000', $params);
    }

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE t.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Mục tiêu của đúng (phạm vi, tiện ích, kỳ) - FN-08 khong_dat_muc_tieu. */
    public function findFor(string $scopeType, int $scopeId, string $utility, int $periodId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM consumption_targets WHERE scope_type = :t AND scope_id = :i AND utility_type = :u AND billing_period_id = :p'
        );
        $stmt->execute(['t' => $scopeType, 'i' => $scopeId, 'u' => $utility, 'p' => $periodId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Thêm; trùng (phạm vi, tiện ích, kỳ) -> null (UNIQUE chặn cả 2 request đồng thời). */
    public function insertUnlessDuplicate(array $data): ?int
    {
        try {
            return $this->insertRow($data);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }
    }
}
