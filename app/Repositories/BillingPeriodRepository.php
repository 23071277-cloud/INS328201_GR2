<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/**
 * Bảng billing_periods (FN-04.01). STEP 7 dùng lastLockedEndDate() cho VAL-03.04;
 * STEP 8 thêm tạo/tra cứu kỳ. Khoá / mở lại kỳ thuộc FN-05.07/05.08 - KHÔNG có ở đây.
 */
class BillingPeriodRepository extends BaseRepository
{
    protected string $table = 'billing_periods';

    /** Ngày kết thúc của kỳ đã khoá muộn nhất (null nếu chưa có kỳ khoá). Kỳ 'mo_lai' không tính. */
    public function lastLockedEndDate(): ?string
    {
        $value = $this->scalarQuery("SELECT MAX(end_date) FROM billing_periods WHERE status = 'da_khoa'");

        return $value === null || $value === false ? null : (string) $value;
    }

    /** @param array{status:string,year:string} $filters */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        if ($filters['status'] !== '') {
            $where[] = 'p.status = :status';
            $params['status'] = $filters['status'];
        }
        if ($filters['year'] !== '') {
            $where[] = 'YEAR(p.start_date) = :year';
            $params['year'] = (int) $filters['year'];
        }

        $fromWhere = 'FROM billing_periods p LEFT JOIN users u ON u.id = p.created_by' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $select = "p.*, u.full_name AS created_by_name,
            (SELECT COUNT(*) FROM meter_readings r WHERE r.billing_period_id = p.id) AS reading_count,
            (SELECT COUNT(*) FROM meter_readings r WHERE r.billing_period_id = p.id AND r.status = 'nhap') AS draft_count";

        return $this->paginateQuery($select, $fromWhere, $params, 'p.start_date DESC, p.id DESC', $page, $perPage);
    }

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT p.*, u.full_name AS created_by_name FROM billing_periods p LEFT JOIN users u ON u.id = p.created_by WHERE p.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function codeExists(string $code): bool
    {
        return (int) $this->scalarQuery('SELECT COUNT(*) FROM billing_periods WHERE period_code = :c', ['c' => $code]) > 0;
    }

    /** Kỳ có khoảng ngày giao với [start, end] (không cho 2 kỳ chồng ngày - [TECHNICAL ASSUMPTION]). */
    public function overlapping(string $start, string $end): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM billing_periods WHERE start_date <= :e AND end_date >= :s LIMIT 1');
        $stmt->execute(['s' => $start, 'e' => $end]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Kỳ liền trước (kết thúc trước ngày bắt đầu). */
    public function previousOf(string $startDate): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM billing_periods WHERE end_date < :s ORDER BY end_date DESC LIMIT 1');
        $stmt->execute(['s' => $startDate]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function years(): array
    {
        return array_map('intval', array_column($this->fetchAllQuery('SELECT DISTINCT YEAR(start_date) AS y FROM billing_periods ORDER BY y DESC'), 'y'));
    }

    /** Thống kê kỳ: công tơ cần ghi (đang hoạt động, căn đang hoạt động), đã nhập, nháp, đã xác nhận. */
    public function stats(int $periodId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                (SELECT COUNT(*) FROM meters m JOIN apartments a ON a.id = m.apartment_id
                  WHERE m.status = 'hoat_dong' AND a.status = 'hoat_dong') AS active_meters,
                (SELECT COUNT(*) FROM meter_readings r WHERE r.billing_period_id = :p1) AS entered,
                (SELECT COUNT(*) FROM meter_readings r WHERE r.billing_period_id = :p2 AND r.status = 'nhap') AS draft,
                (SELECT COUNT(*) FROM meter_readings r WHERE r.billing_period_id = :p3 AND r.status = 'da_xac_nhan') AS confirmed,
                (SELECT COUNT(*) FROM meters m JOIN apartments a ON a.id = m.apartment_id
                  WHERE m.status = 'hoat_dong' AND a.status = 'hoat_dong'
                    AND NOT EXISTS (SELECT 1 FROM meter_readings r WHERE r.meter_id = m.id AND r.billing_period_id = :p4)) AS pending"
        );
        $stmt->execute(['p1' => $periodId, 'p2' => $periodId, 'p3' => $periodId, 'p4' => $periodId]);

        return array_map('intval', $stmt->fetch());
    }

    /**
     * Danh sách công tơ chờ ghi số của kỳ (FN-04.01 bước 3): đang hoạt động, căn đang hoạt động,
     * chưa có chỉ số trong kỳ. Tính động (schema không có bảng "danh sách chờ", current_reading NOT NULL).
     */
    public function pendingMeters(int $periodId): array
    {
        return $this->fetchAllQuery(
            "SELECT m.id, m.meter_code, m.utility_type, m.usage_type, m.display_label, m.initial_reading,
                    a.id AS apartment_id, a.code AS apartment_code, b.code AS building_code, z.code AS zone_code
             FROM meters m
             JOIN apartments a ON a.id = m.apartment_id
             JOIN buildings b ON b.id = a.building_id
             LEFT JOIN zones z ON z.id = a.zone_id
             WHERE m.status = 'hoat_dong' AND a.status = 'hoat_dong'
               AND NOT EXISTS (SELECT 1 FROM meter_readings r WHERE r.meter_id = m.id AND r.billing_period_id = :p)
             ORDER BY b.code, a.code, m.utility_type, m.meter_code",
            ['p' => $periodId]
        );
    }

    /**
     * UI chi tiết kỳ (chỉ đọc): công tơ đang hoạt động - CÙNG điều kiện với stats()['active_meters'] (công tơ + căn đang hoạt động)
     * nên số dòng luôn khớp thẻ "Công tơ đang hoạt động" - kèm chỉ số của kỳ (nếu có). Cùng thứ tự với danh sách chờ ghi số.
     */
    public function activeMeters(int $periodId): array
    {
        return $this->fetchAllQuery(
            "SELECT m.id, m.meter_code, m.utility_type, a.code AS apartment_code, b.code AS building_code,
                    r.id AS reading_id, r.status AS reading_status, r.note AS reading_note
             FROM meters m
             JOIN apartments a ON a.id = m.apartment_id
             JOIN buildings b ON b.id = a.building_id
             LEFT JOIN meter_readings r ON r.meter_id = m.id AND r.billing_period_id = :p
             WHERE m.status = 'hoat_dong' AND a.status = 'hoat_dong'
             ORDER BY b.code, a.code, m.utility_type, m.meter_code",
            ['p' => $periodId]
        );
    }

    /** FN-05.07: lô import chưa xác nhận/huỷ của kỳ. */
    public function pendingImportCount(int $periodId): int
    {
        return (int) $this->scalarQuery(
            "SELECT COUNT(*) FROM import_batches WHERE billing_period_id = :p AND status IN ('da_tai_len', 'da_kiem_tra')",
            ['p' => $periodId]
        );
    }

    /** FN-05.08 (J-19): mã các kỳ bắt đầu sau ngày cho trước đã có chỉ số (CSĐ có thể phụ thuộc kỳ đang mở lại). */
    public function laterPeriodsWithReadings(string $afterDate): array
    {
        return array_column($this->fetchAllQuery(
            'SELECT p.period_code FROM billing_periods p
             WHERE p.start_date > :d AND EXISTS (SELECT 1 FROM meter_readings r WHERE r.billing_period_id = p.id)
             ORDER BY p.start_date',
            ['d' => $afterDate]
        ), 'period_code');
    }

    public function options(): array
    {
        return $this->fetchAllQuery('SELECT id, period_code, status, start_date FROM billing_periods ORDER BY start_date DESC');
    }
}
