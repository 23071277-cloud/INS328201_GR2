<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/** FN-06.01 - bảng baselines (mỗi dòng là một PHIÊN BẢN baseline của (phạm vi, tiện ích) theo ngày hiệu lực). */
class BaselineRepository extends BaseRepository
{
    protected string $table = 'baselines';

    /** Vòng đời hiển thị tính theo ngày (như FN-03 P2) - không lưu DB. */
    private const LIFECYCLE = "CASE
            WHEN b.status = 'het_hieu_luc' THEN 'het_hieu_luc'
            WHEN b.effective_date > :today1 THEN 'sap_ap_dung'
            WHEN b.id = (SELECT c.id FROM baselines c WHERE c.scope_type = b.scope_type AND c.scope_id = b.scope_id
                         AND c.utility_type = b.utility_type AND c.status = 'hieu_luc' AND c.effective_date <= :today2
                         ORDER BY c.effective_date DESC, c.id DESC LIMIT 1) THEN 'dang_ap_dung'
            ELSE 'da_thay_the' END";

    /** @param array{scope_type:string,utility_type:string,method:string} $filters */
    public function search(array $filters, string $today): array
    {
        $where = [];
        $params = ['today1' => $today, 'today2' => $today];
        foreach (['scope_type', 'utility_type', 'method'] as $key) {
            if ($filters[$key] !== '') {
                $where[] = "b.{$key} = :{$key}";
                $params[$key] = $filters[$key];
            }
        }

        return $this->fetchAllQuery(
            'SELECT b.*, ' . self::LIFECYCLE . ' AS lifecycle, u.full_name AS created_by_name
             FROM baselines b LEFT JOIN users u ON u.id = b.created_by'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY b.scope_type, b.scope_id, b.utility_type, b.effective_date DESC, b.id DESC LIMIT 500',
            $params
        );
    }

    public function findDetailed(int $id, string $today): ?array
    {
        $stmt = $this->db->prepare('SELECT b.*, ' . self::LIFECYCLE . ' AS lifecycle, u.full_name AS created_by_name
            FROM baselines b LEFT JOIN users u ON u.id = b.created_by WHERE b.id = :id');
        $stmt->execute(['id' => $id, 'today1' => $today, 'today2' => $today]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Phiên bản hiệu lực của 1 phạm vi tại ngày (R1): hieu_luc, effective_date <= ngày, mới nhất. */
    public function effectiveFor(string $scopeType, int $scopeId, string $utility, string $date): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM baselines WHERE scope_type = :t AND scope_id = :i AND utility_type = :u
               AND status = 'hieu_luc' AND effective_date <= :d ORDER BY effective_date DESC, id DESC LIMIT 1"
        );
        $stmt->execute(['t' => $scopeType, 'i' => $scopeId, 'u' => $utility, 'd' => $date]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Phiên bản hiệu lực của MỌI thực thể 1 loại phạm vi tại ngày. @return array<int,array> scope_id => dòng */
    public function effectiveMap(string $scopeType, string $utility, string $date): array
    {
        $rows = $this->fetchAllQuery(
            "SELECT b.* FROM baselines b
             WHERE b.scope_type = :t AND b.utility_type = :u AND b.status = 'hieu_luc' AND b.effective_date <= :d
               AND b.id = (SELECT c.id FROM baselines c WHERE c.scope_type = b.scope_type AND c.scope_id = b.scope_id
                           AND c.utility_type = b.utility_type AND c.status = 'hieu_luc' AND c.effective_date <= :d2
                           ORDER BY c.effective_date DESC, c.id DESC LIMIT 1)",
            ['t' => $scopeType, 'u' => $utility, 'd' => $date, 'd2' => $date]
        );

        return array_column($rows, null, 'scope_id');
    }

    /** R7: đã có phiên bản hiệu lực cùng (phạm vi, tiện ích, ngày hiệu lực). */
    public function duplicateExists(string $scopeType, int $scopeId, string $utility, string $date, int $exceptId = 0): bool
    {
        return (int) $this->scalarQuery(
            "SELECT COUNT(*) FROM baselines WHERE scope_type = :t AND scope_id = :i AND utility_type = :u
               AND effective_date = :d AND status = 'hieu_luc' AND id <> :x",
            ['t' => $scopeType, 'i' => $scopeId, 'u' => $utility, 'd' => $date, 'x' => $exceptId]
        ) > 0;
    }
}
