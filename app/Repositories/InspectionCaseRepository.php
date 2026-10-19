<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/** FN-07.07 / 07.08 - inspection_cases (bản Core tối thiểu, A-03 / K-03; lịch, ảnh, hạn: FN-10.01). */
class InspectionCaseRepository extends BaseRepository
{
    protected string $table = 'inspection_cases';

    public function casesOf(int $alertId): array
    {
        return $this->fetchAllQuery(
            'SELECT c.*, ua.full_name AS assigned_to_name, uc.full_name AS created_by_name
             FROM inspection_cases c LEFT JOIN users ua ON ua.id = c.assigned_to LEFT JOIN users uc ON uc.id = c.created_by
             WHERE c.alert_id = :a ORDER BY c.id',
            ['a' => $alertId]
        );
    }

    /** Case chưa đóng của cảnh báo (tối đa 1 - [ĐX] FN-07.07 mục 9). */
    public function openCaseOf(int $alertId, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM inspection_cases WHERE alert_id = :a AND status <> 'da_dong' ORDER BY id DESC LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['a' => $alertId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Kỹ thuật đang hoạt động (người nhận case). */
    public function technicians(): array
    {
        return $this->fetchAllQuery("SELECT id, full_name, username FROM users WHERE role = 'ky_thuat' AND status = 'hoat_dong' ORDER BY full_name");
    }

    public function isActiveTechnician(int $userId): bool
    {
        return (int) $this->scalarQuery("SELECT COUNT(*) FROM users WHERE id = :id AND role = 'ky_thuat' AND status = 'hoat_dong'", ['id' => $userId]) > 0;
    }
}
