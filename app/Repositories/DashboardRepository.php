<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/**
 * FN-09 - truy vấn tổng hợp CHỈ ĐỌC cho dashboard / báo cáo (GROUP BY / SUM / COUNT / CASE, không N+1).
 * Phạm vi: tòa (bắt buộc) + khu (tuỳ chọn, theo danh mục HIỆN TẠI - giới hạn U5).
 */
class DashboardRepository extends BaseRepository
{
    protected string $table = 'billing_periods';

    /** Kỳ mới nhất có chỉ số Đã xác nhận ([ĐX] FN-09.01 mục 9). */
    public function latestPeriodWithData(): ?array
    {
        $row = $this->db->query(
            "SELECT p.* FROM billing_periods p
             WHERE EXISTS (SELECT 1 FROM meter_readings r WHERE r.billing_period_id = p.id AND r.status = 'da_xac_nhan')
             ORDER BY p.start_date DESC LIMIT 1"
        )->fetch();

        return $row ?: null;
    }

    /** N kỳ gần nhất tính tới kỳ đang xem (cũ -> mới). */
    public function periodsUpTo(string $startDate, int $n): array
    {
        $stmt = $this->db->prepare('SELECT * FROM billing_periods WHERE start_date <= :d ORDER BY start_date DESC LIMIT ' . max(1, $n));
        $stmt->execute(['d' => $startDate]);

        return array_reverse($stmt->fetchAll());
    }

    public function buildings(): array
    {
        return $this->fetchAllQuery("SELECT id, code, name, status FROM buildings ORDER BY code");
    }

    public function zones(?int $buildingId): array
    {
        return $buildingId === null
            ? $this->fetchAllQuery('SELECT id, building_id, code, name FROM zones ORDER BY code')
            : $this->fetchAllQuery('SELECT id, building_id, code, name FROM zones WHERE building_id = :b ORDER BY code', ['b' => $buildingId]);
    }

    /** Căn thuộc phạm vi (id => thông tin) - 1 query. */
    public function apartmentsInScope(int $buildingId, ?int $zoneId, ?int $apartmentId = null): array
    {
        $sql = 'SELECT a.id, a.code, a.area, a.status, a.zone_id, z.code AS zone_code, z.name AS zone_name, b.code AS building_code
                FROM apartments a JOIN buildings b ON b.id = a.building_id LEFT JOIN zones z ON z.id = a.zone_id
                WHERE a.building_id = :b';
        $params = ['b' => $buildingId];
        if ($zoneId !== null) {
            $sql .= ' AND a.zone_id = :z';
            $params['z'] = $zoneId;
        }
        if ($apartmentId !== null) {
            $sql .= ' AND a.id = :a';
            $params['a'] = $apartmentId;
        }

        return array_column($this->fetchAllQuery($sql . ' ORDER BY a.code', $params), null, 'id');
    }

    /** Cảnh báo theo trạng thái × ưu tiên × tiện ích (1 query). $technicianId: Kỹ thuật chỉ phần của mình (K-18). */
    public function alertBreakdown(int $periodId, int $buildingId, ?int $zoneId, ?int $technicianId): array
    {
        $sql = 'SELECT al.status, al.priority, al.utility_type, al.alert_type, COUNT(*) AS n
                FROM alerts al JOIN apartments a ON a.id = al.apartment_id
                WHERE al.billing_period_id = :p AND a.building_id = :b';
        $params = ['p' => $periodId, 'b' => $buildingId];
        if ($zoneId !== null) {
            $sql .= ' AND a.zone_id = :z';
            $params['z'] = $zoneId;
        }
        if ($technicianId !== null) {
            $sql .= ' AND (al.created_by = :t1 OR EXISTS (SELECT 1 FROM inspection_cases c WHERE c.alert_id = al.id AND c.assigned_to = :t2))';
            $params['t1'] = $technicianId;
            $params['t2'] = $technicianId;
        }

        return $this->fetchAllQuery($sql . ' GROUP BY al.status, al.priority, al.utility_type, al.alert_type', $params);
    }

    /** Khuyến nghị (FN-08): số cảnh báo có khuyến nghị, tổng số, 5 khuyến nghị gần nhất. */
    public function recommendationSummary(int $periodId, int $buildingId, ?int $zoneId): array
    {
        $where = 'al.billing_period_id = :p AND a.building_id = :b' . ($zoneId !== null ? ' AND a.zone_id = :z' : '');
        $params = ['p' => $periodId, 'b' => $buildingId] + ($zoneId !== null ? ['z' => $zoneId] : []);
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT x.alert_id) AS alerts, COUNT(*) AS total
            FROM recommendations x JOIN alerts al ON al.id = x.alert_id JOIN apartments a ON a.id = al.apartment_id WHERE {$where}");
        $stmt->execute($params);
        $counts = $stmt->fetch();
        $recent = $this->fetchAllQuery("SELECT x.id, x.alert_id, x.recommendation_text, x.created_at, a.code AS apartment_code, al.utility_type, al.priority, al.status
            FROM recommendations x JOIN alerts al ON al.id = x.alert_id JOIN apartments a ON a.id = al.apartment_id
            WHERE {$where} ORDER BY x.created_at DESC, x.id DESC LIMIT 5", $params);

        return ['alerts' => (int) $counts['alerts'], 'total' => (int) $counts['total'], 'recent' => $recent];
    }

    /** Chi phí FN-05 đã lưu (không tính lại): tổng các bản tính Đã xác nhận trở lên + số bản (1 query). */
    public function costSummary(int $periodId, int $buildingId, ?int $zoneId, array $confirmedStatuses): array
    {
        $params = ['p' => $periodId, 'b' => $buildingId];
        // PDO native prepare không cho lặp placeholder -> mỗi lần dùng danh sách trạng thái có tên riêng
        $in = function (string $tag) use ($confirmedStatuses, &$params): string {
            $names = [];
            foreach (array_values($confirmedStatuses) as $i => $status) {
                $names[] = ":{$tag}{$i}";
                $params["{$tag}{$i}"] = $status;
            }

            return implode(', ', $names);
        };
        $zone = '';
        if ($zoneId !== null) {
            $zone = ' AND a.zone_id = :z';
            $params['z'] = $zoneId;
        }
        $sql = "SELECT COUNT(*) AS charges,
                SUM(c.status IN ({$in('c')})) AS confirmed,
                COALESCE(SUM(CASE WHEN c.status IN ({$in('e')}) THEN c.electricity_amount + c.electricity_vat_amount ELSE 0 END), 0) AS electricity_cost,
                COALESCE(SUM(CASE WHEN c.status IN ({$in('w')}) THEN c.water_amount ELSE 0 END), 0) AS water_cost,
                COALESCE(SUM(CASE WHEN c.status IN ({$in('f')}) THEN c.service_fee_amount + c.fire_insurance_amount + c.parking_fee_amount ELSE 0 END), 0) AS fixed_fee,
                COALESCE(SUM(CASE WHEN c.status IN ({$in('t')}) THEN c.total_arising ELSE 0 END), 0) AS total_arising
             FROM apartment_charges c JOIN apartments a ON a.id = c.apartment_id
             WHERE c.billing_period_id = :p AND a.building_id = :b{$zone}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch();
    }

    /** Chủ hộ đang hiệu lực của các căn (BR-36 - chỉ gọi khi role có quyền xem cư dân). */
    public function owners(array $apartmentIds): array
    {
        if ($apartmentIds === []) {
            return [];
        }
        $in = [];
        $params = [];
        foreach (array_values($apartmentIds) as $i => $id) {
            $in[] = ':a' . $i;
            $params['a' . $i] = (int) $id;
        }
        $rows = $this->fetchAllQuery(
            "SELECT apartment_id, full_name FROM residents WHERE resident_role = 'chu_ho' AND is_active = 1 AND apartment_id IN (" . implode(', ', $in) . ') ORDER BY id',
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['apartment_id']] ??= $r['full_name'];
        }

        return $out;
    }
}
