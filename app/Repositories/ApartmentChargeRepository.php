<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/** FN-05 - apartment_charges (UNIQUE apartment_id + billing_period_id) và charge_tier_details. */
class ApartmentChargeRepository extends BaseRepository
{
    public function updateById(int $id, array $data): void
    {
        \App\Services\IssuedChargeGuard::assertMutable($id);
        parent::updateById($id, $data);
    }

    public function delete(int $id): bool
    {
        \App\Services\IssuedChargeGuard::assertMutable($id);
        return parent::delete($id);
    }
    protected string $table = 'apartment_charges';

    /** Cột tiền/lượng của bản tính - so sánh tính nhất quán (Q12) và audit. */
    public const AMOUNT_COLUMNS = [
        'electricity_consumption', 'electricity_amount', 'electricity_vat_amount',
        'water_consumption', 'water_amount',
        'service_fee_amount', 'fire_insurance_amount', 'parking_fee_amount',
        'total_arising', 'old_debt_amount', 'total_due',
    ];

    private const DETAIL_SELECT = 'SELECT c.*, a.code AS apartment_code, a.area, a.occupancy_status, a.status AS apartment_status,
            a.zone_id, a.building_id, b.code AS building_code, z.code AS zone_code,
            p.period_code, p.status AS period_status, p.start_date, p.end_date,
            uc.full_name AS confirmed_by_name, ua.full_name AS approved_by_name
        FROM apartment_charges c
        JOIN apartments a ON a.id = c.apartment_id
        JOIN buildings b ON b.id = a.building_id
        LEFT JOIN zones z ON z.id = a.zone_id
        JOIN billing_periods p ON p.id = c.billing_period_id
        LEFT JOIN users uc ON uc.id = c.confirmed_by
        LEFT JOIN users ua ON ua.id = c.approved_by';

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare(self::DETAIL_SELECT . ' WHERE c.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByApartmentPeriod(int $apartmentId, int $periodId, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM apartment_charges WHERE apartment_id = :a AND billing_period_id = :p' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['a' => $apartmentId, 'p' => $periodId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Bản tính của kỳ, theo tòa / mã căn. */
    public function listByPeriod(int $periodId): array
    {
        return $this->fetchAllQuery(self::DETAIL_SELECT . ' WHERE c.billing_period_id = :p ORDER BY b.code, a.code', ['p' => $periodId]);
    }

    /** Chi tiết bậc kèm công tơ / chỉ số (giải trình: căn -> công tơ -> chỉ số -> CL -> bậc -> tiền). */
    public function tierDetails(int $chargeId): array
    {
        return $this->fetchAllQuery(
            'SELECT d.*, r.meter_id, r.previous_reading, r.current_reading, r.consumption AS reading_consumption,
                    r.status AS reading_status, r.updated_at AS reading_updated_at,
                    m.meter_code, m.usage_type, m.display_label
             FROM charge_tier_details d
             JOIN meter_readings r ON r.id = d.meter_reading_id
             JOIN meters m ON m.id = r.meter_id
             WHERE d.apartment_charge_id = :c
             ORDER BY d.utility_type, m.meter_code, d.meter_reading_id, d.tier_order',
            ['c' => $chargeId]
        );
    }

    public function deleteTierDetails(int $chargeId): void
    {
        \App\Services\IssuedChargeGuard::assertMutable($chargeId);
        $stmt = $this->db->prepare('DELETE FROM charge_tier_details WHERE apartment_charge_id = :c');
        $stmt->execute(['c' => $chargeId]);
    }

    public function insertTierDetail(array $row): void
    {
        \App\Services\IssuedChargeGuard::assertMutable((int)$row['charge']);
        $stmt = $this->db->prepare(
            'INSERT INTO charge_tier_details (apartment_charge_id, meter_reading_id, utility_type, tier_order, tier_from, tier_to, quantity, unit_price, amount)
             VALUES (:charge, :reading, :utility, :tier_order, :tier_from, :tier_to, :quantity, :unit_price, :amount)'
        );
        $stmt->execute($row);
    }

    /**
     * Căn được tính phí của kỳ [ASSUMPTION Q9]: căn đang hoạt động + căn đã có chỉ số hoặc bản tính trong kỳ.
     */
    public function billableApartments(int $periodId): array
    {
        return $this->fetchAllQuery(
            "SELECT a.*, b.code AS building_code, z.code AS zone_code
             FROM apartments a
             JOIN buildings b ON b.id = a.building_id
             LEFT JOIN zones z ON z.id = a.zone_id
             WHERE a.status = 'hoat_dong'
                OR EXISTS (SELECT 1 FROM apartment_charges c WHERE c.apartment_id = a.id AND c.billing_period_id = :p1)
                OR EXISTS (SELECT 1 FROM meter_readings r JOIN meters m ON m.id = r.meter_id
                           WHERE m.apartment_id = a.id AND r.billing_period_id = :p2)
             ORDER BY b.code, a.code",
            ['p1' => $periodId, 'p2' => $periodId]
        );
    }

    /** Chỉ số của mọi công tơ thuộc căn trong kỳ (kể cả công tơ đã ngừng sau khi ghi). */
    public function readingsOf(int $apartmentId, int $periodId): array
    {
        return $this->fetchAllQuery(
            'SELECT r.*, m.meter_code, m.utility_type, m.usage_type, m.display_label, m.status AS meter_status
             FROM meter_readings r JOIN meters m ON m.id = r.meter_id
             WHERE m.apartment_id = :a AND r.billing_period_id = :p
             ORDER BY m.utility_type, m.meter_code',
            ['a' => $apartmentId, 'p' => $periodId]
        );
    }

    /** Công tơ đang hoạt động của căn CHƯA có chỉ số trong kỳ. */
    public function metersWithoutReading(int $apartmentId, int $periodId): array
    {
        return $this->fetchAllQuery(
            "SELECT m.id, m.meter_code, m.utility_type FROM meters m
             WHERE m.apartment_id = :a AND m.status = 'hoat_dong'
               AND NOT EXISTS (SELECT 1 FROM meter_readings r WHERE r.meter_id = m.id AND r.billing_period_id = :p)
             ORDER BY m.meter_code",
            ['a' => $apartmentId, 'p' => $periodId]
        );
    }

    /** @return array<string,int> vehicle_type => quantity (FN-02.06) */
    public function vehiclesOf(int $apartmentId): array
    {
        $rows = $this->fetchAllQuery('SELECT vehicle_type, quantity FROM parking_vehicles WHERE apartment_id = :a', ['a' => $apartmentId]);

        return array_map('intval', array_column($rows, 'quantity', 'vehicle_type'));
    }

    /** @return array<string,int> status => số bản tính */
    public function statusCounts(int $periodId): array
    {
        $rows = $this->fetchAllQuery(
            'SELECT status, COUNT(*) AS n FROM apartment_charges WHERE billing_period_id = :p GROUP BY status',
            ['p' => $periodId]
        );

        return array_map('intval', array_column($rows, 'n', 'status'));
    }

    /** Bản tính bị trả lại (không kèm số tiền) - cho Kỹ thuật/BQL nhận thông tin [ĐX] Q17. */
    public function returnedOfPeriod(int $periodId): array
    {
        return $this->fetchAllQuery(
            "SELECT c.id, c.rejection_reason, c.updated_at, a.code AS apartment_code, b.code AS building_code
             FROM apartment_charges c JOIN apartments a ON a.id = c.apartment_id JOIN buildings b ON b.id = a.building_id
             WHERE c.billing_period_id = :p AND c.status = 'bi_tra_lai' ORDER BY b.code, a.code",
            ['p' => $periodId]
        );
    }

    /** Số cảnh báo chưa đóng của căn trong kỳ ([ĐX] FN-05.06 mục 9: báo Kế toán trước khi xác nhận). */
    public function openAlertCount(int $apartmentId, int $periodId): int
    {
        return (int) $this->scalarQuery(
            "SELECT COUNT(*) FROM alerts WHERE apartment_id = :a AND billing_period_id = :p AND status <> 'da_dong'",
            ['a' => $apartmentId, 'p' => $periodId]
        );
    }

    /**
     * FN-05.05 - tổng hợp chi phí theo cấp bằng SQL (mỗi bản tính đếm đúng 1 lần, không join bảng bậc).
     * @param string $level 'building' | 'zone' | 'apartment'
     * @param string[] $statuses trạng thái bản tính được tính vào (BR-11)
     */
    public function aggregate(int $periodId, string $level, array $statuses): array
    {
        [$groupSelect, $groupBy, $orderBy] = match ($level) {
            'building' => ['b.id AS group_id, b.code AS group_code, b.name AS group_name', 'b.id, b.code, b.name', 'b.code'],
            'zone' => ["z.id AS group_id, COALESCE(z.code, '') AS group_code, COALESCE(z.name, '') AS group_name, b.code AS building_code",
                'z.id, z.code, z.name, b.code', 'b.code, z.code'],
            default => ['a.id AS group_id, a.code AS group_code, a.code AS group_name, b.code AS building_code, z.code AS zone_code, c.status AS charge_status',
                'a.id, a.code, b.code, z.code, c.status', 'b.code, a.code'],
        };

        $params = ['p' => $periodId];
        $in = [];
        foreach (array_values($statuses) as $i => $status) {
            $in[] = ':s' . $i;
            $params['s' . $i] = $status;
        }

        return $this->fetchAllQuery(
            "SELECT {$groupSelect},
                    COUNT(c.id) AS charged_apartments,
                    SUM(a.area) AS total_area,
                    SUM(c.electricity_consumption) AS electricity_consumption,
                    SUM(c.water_consumption) AS water_consumption,
                    SUM(c.electricity_amount + c.electricity_vat_amount) AS electricity_cost,
                    SUM(c.water_amount) AS water_cost,
                    SUM(c.service_fee_amount + c.fire_insurance_amount + c.parking_fee_amount) AS fixed_fee_cost,
                    SUM(c.total_arising) AS total_cost
             FROM apartment_charges c
             JOIN apartments a ON a.id = c.apartment_id
             JOIN buildings b ON b.id = a.building_id
             LEFT JOIN zones z ON z.id = a.zone_id
             WHERE c.billing_period_id = :p AND c.status IN (" . implode(', ', $in) . ")
             GROUP BY {$groupBy}
             ORDER BY {$orderBy}",
            $params
        );
    }
}
