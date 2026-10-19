<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use InvalidArgumentException;

/**
 * FN-06 - truy vấn tổng hợp tiêu thụ theo phạm vi (công tơ / căn / khu / tòa) và tra cứu thực thể phạm vi.
 * Không ghi dữ liệu. Phạm vi đa hình (A2): scope_id là id thực thể theo scope_type.
 */
class ConsumptionAnalyticsRepository extends BaseRepository
{
    protected string $table = 'meter_readings';

    /** Biểu thức id phạm vi trên join meters m / apartments a. */
    private static function scopeExpr(string $scopeType): string
    {
        return match ($scopeType) {
            'meter' => 'm.id',
            'apartment' => 'a.id',
            'zone' => 'a.zone_id',
            'building' => 'a.building_id',
            default => throw new InvalidArgumentException('scope_type không hợp lệ'),
        };
    }

    /**
     * Tổng CL theo phạm vi trong 1 kỳ, 1 tiện ích (mỗi chỉ số đếm đúng 1 lần).
     * @return array<int, array{confirmed_sum:string, confirmed_count:int, draft_count:int, changed_count:int}>
     */
    public function consumptionByScope(string $scopeType, string $utility, int $periodId, ?int $scopeId = null): array
    {
        $expr = self::scopeExpr($scopeType);
        $params = ['p' => $periodId, 'u' => $utility];
        $filter = '';
        if ($scopeId !== null) {
            $filter = " AND {$expr} = :sid";
            $params['sid'] = $scopeId;
        }
        $rows = $this->fetchAllQuery(
            "SELECT {$expr} AS scope_id,
                    COALESCE(SUM(CASE WHEN r.status = 'da_xac_nhan' THEN r.consumption ELSE 0 END), 0) AS confirmed_sum,
                    SUM(r.status = 'da_xac_nhan') AS confirmed_count,
                    SUM(r.status = 'nhap') AS draft_count,
                    SUM(r.is_meter_changed = 1) AS changed_count
             FROM meter_readings r
             JOIN meters m ON m.id = r.meter_id
             JOIN apartments a ON a.id = m.apartment_id
             WHERE r.billing_period_id = :p AND m.utility_type = :u{$filter} AND {$expr} IS NOT NULL
             GROUP BY {$expr}",
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['scope_id']] = [
                'confirmed_sum' => (string) $row['confirmed_sum'],
                'confirmed_count' => (int) $row['confirmed_count'],
                'draft_count' => (int) $row['draft_count'],
                'changed_count' => (int) $row['changed_count'],
            ];
        }

        return $out;
    }

    /** Công tơ đang hoạt động (căn đang hoạt động) của phạm vi CHƯA có chỉ số trong kỳ. @return array<int,int> scope_id => số công tơ */
    public function missingByScope(string $scopeType, string $utility, int $periodId, ?int $scopeId = null): array
    {
        $expr = self::scopeExpr($scopeType);
        $params = ['p' => $periodId, 'u' => $utility];
        $filter = '';
        if ($scopeId !== null) {
            $filter = " AND {$expr} = :sid";
            $params['sid'] = $scopeId;
        }
        $rows = $this->fetchAllQuery(
            "SELECT {$expr} AS scope_id, COUNT(*) AS n
             FROM meters m JOIN apartments a ON a.id = m.apartment_id
             WHERE m.status = 'hoat_dong' AND a.status = 'hoat_dong' AND m.utility_type = :u{$filter} AND {$expr} IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM meter_readings r WHERE r.meter_id = m.id AND r.billing_period_id = :p)
             GROUP BY {$expr}",
            $params
        );

        return array_map('intval', array_column($rows, 'n', 'scope_id'));
    }

    /** Thực thể phạm vi (kèm trạng thái; công tơ kèm tiện ích). $forUpdate: khoá dòng để tuần tự hoá thao tác cùng phạm vi. */
    public function scopeEntity(string $scopeType, int $id, bool $forUpdate = false): ?array
    {
        $sql = match ($scopeType) {
            'meter' => 'SELECT id, status, utility_type, meter_code AS code FROM meters WHERE id = :id',
            'apartment' => 'SELECT id, status, code FROM apartments WHERE id = :id',
            'zone' => 'SELECT id, status, code FROM zones WHERE id = :id',
            'building' => 'SELECT id, status, code FROM buildings WHERE id = :id',
            default => null,
        };
        if ($sql === null) {
            return null;
        }
        $stmt = $this->db->prepare($sql . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Danh sách thực thể của một loại phạm vi, có nhãn hiển thị. */
    public function scopeEntities(string $scopeType, bool $activeOnly = false): array
    {
        $active = $activeOnly ? ' WHERE x.status = \'hoat_dong\'' : '';
        $sql = match ($scopeType) {
            'meter' => "SELECT x.id, x.status, x.utility_type, CONCAT(b.code, ' / ', a.code, ' / ', x.meter_code) AS label
                        FROM meters x JOIN apartments a ON a.id = x.apartment_id JOIN buildings b ON b.id = a.building_id{$active}
                        ORDER BY b.code, a.code, x.meter_code",
            'apartment' => "SELECT x.id, x.status, NULL AS utility_type, CONCAT(b.code, ' / ', x.code) AS label
                        FROM apartments x JOIN buildings b ON b.id = x.building_id{$active} ORDER BY b.code, x.code",
            'zone' => "SELECT x.id, x.status, NULL AS utility_type, CONCAT(b.code, ' / ', x.code, ' — ', x.name) AS label
                        FROM zones x JOIN buildings b ON b.id = x.building_id{$active} ORDER BY b.code, x.code",
            'building' => "SELECT x.id, x.status, NULL AS utility_type, CONCAT(x.code, ' — ', x.name) AS label
                        FROM buildings x{$active} ORDER BY x.code",
            default => throw new InvalidArgumentException('scope_type không hợp lệ'),
        };

        return $this->fetchAllQuery($sql);
    }

    /** Kỳ có end_date < ngày cho trước, mới nhất trước. */
    public function periodsEndingBefore(string $date): array
    {
        return $this->fetchAllQuery('SELECT * FROM billing_periods WHERE end_date < :d ORDER BY end_date DESC', ['d' => $date]);
    }

    public function periodByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM billing_periods WHERE period_code = :c');
        $stmt->execute(['c' => $code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
