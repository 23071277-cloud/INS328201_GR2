<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use App\Core\Schema;
use PDOException;

/**
 * FN-07 - alerts (UNIQUE uq_alert_dedupe = rule + căn + COALESCE(công tơ, 0) + kỳ) + alert_status_history.
 * [STEP 4C] Schema v2: lịch sử trạng thái = alert_events event_type 'state_change' (changed_by / changed_at = created_by / created_at).
 */
class AlertRepository extends BaseRepository
{
    protected string $table = 'alerts';

    private const SELECT = "SELECT al.*, a.code AS apartment_code, b.code AS building_code, z.code AS zone_code, a.zone_id,
            m.meter_code, m.usage_type, m.display_label, p.period_code, p.status AS period_status,
            r.name AS rule_name, r.status AS rule_status,
            uc.full_name AS created_by_name, ur.full_name AS reviewed_by_name, ux.full_name AS closed_by_name, ucl.full_name AS concluded_by_name,
            a.occupancy_status, a.status AS apartment_status,
            DATEDIFF(COALESCE(al.closed_at, NOW()), al.created_at) AS days_open
        FROM alerts al
        JOIN apartments a ON a.id = al.apartment_id
        JOIN buildings b ON b.id = a.building_id
        LEFT JOIN zones z ON z.id = a.zone_id
        LEFT JOIN meters m ON m.id = al.meter_id
        JOIN billing_periods p ON p.id = al.billing_period_id
        LEFT JOIN alert_rules r ON r.id = al.alert_rule_id
        LEFT JOIN users uc ON uc.id = al.created_by
        LEFT JOIN users ur ON ur.id = al.reviewed_by
        LEFT JOIN users ux ON ux.id = al.closed_by
        LEFT JOIN users ucl ON ucl.id = al.concluded_by";

    /**
     * FN-07.04. $technicianId != null: chỉ cảnh báo do người đó tạo hoặc có case giao cho người đó (S12, K-18).
     * @param array{period_id:string,utility_type:string,zone_id:string,q:string,source:string,priority:string,status:string,rule_id:string} $f
     */
    public function search(array $f, ?int $technicianId, int $limit = 500): array
    {
        $where = [];
        $params = [];
        foreach (['period_id' => 'al.billing_period_id', 'zone_id' => 'a.zone_id', 'rule_id' => 'al.alert_rule_id', 'building_id' => 'a.building_id'] as $key => $col) {
            if (($f[$key] ?? '') !== '' && ctype_digit($f[$key])) {
                $where[] = "{$col} = :{$key}";
                $params[$key] = (int) $f[$key];
            }
        }
        foreach (['utility_type', 'source', 'priority', 'alert_type'] as $key) {
            if (($f[$key] ?? '') !== '') {
                $where[] = "al.{$key} = :{$key}";
                $params[$key] = $f[$key];
            }
        }
        if ($f['status'] === 'open' || $f['status'] === '') {
            $where[] = "al.status <> 'da_dong'"; // mặc định: chưa đóng ([ĐX] FN-07.04)
        } elseif ($f['status'] !== 'all') {
            $where[] = 'al.status = :status';
            $params['status'] = $f['status'];
        }
        if ($f['q'] !== '') {
            $where[] = 'a.code LIKE :q';
            $params['q'] = $this->likeParam($f['q']);
        }
        if ($technicianId !== null) {
            $where[] = '(al.created_by = :t1 OR EXISTS (SELECT 1 FROM inspection_cases c WHERE c.alert_id = al.id AND c.assigned_to = :t2))';
            $params['t1'] = $technicianId;
            $params['t2'] = $technicianId;
        }

        return $this->fetchAllQuery(self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY FIELD(al.priority, 'cao', 'trung_binh', 'thap'), al.created_at ASC, al.id ASC LIMIT " . max(1, min(500, $limit)), $params);
    }

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE al.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function visibleToTechnician(int $alertId, int $userId): bool
    {
        return (int) $this->scalarQuery(
            'SELECT COUNT(*) FROM alerts al WHERE al.id = :id AND (al.created_by = :u1 OR EXISTS (SELECT 1 FROM inspection_cases c WHERE c.alert_id = al.id AND c.assigned_to = :u2))',
            ['id' => $alertId, 'u1' => $userId, 'u2' => $userId]
        ) > 0;
    }

    /** Thêm cảnh báo; trùng khoá chống trùng (BR-40) -> null. */
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

    public function existsFor(int $ruleId, int $apartmentId, int $meterId, int $periodId): bool
    {
        return (int) $this->scalarQuery(
            'SELECT COUNT(*) FROM alerts WHERE alert_rule_id = :r AND apartment_id = :a AND dedupe_meter_id = :m AND billing_period_id = :p',
            ['r' => $ruleId, 'a' => $apartmentId, 'm' => $meterId, 'p' => $periodId]
        ) > 0;
    }

    /** Cảnh báo chưa đóng cùng căn / công tơ / kỳ ([ĐX] FN-07.03 mục 9 - chỉ để thông báo). */
    public function openFor(int $apartmentId, ?int $meterId, int $periodId): int
    {
        return (int) $this->scalarQuery(
            "SELECT COUNT(*) FROM alerts WHERE apartment_id = :a AND dedupe_meter_id = :m AND billing_period_id = :p AND status <> 'da_dong'",
            ['a' => $apartmentId, 'm' => $meterId ?? 0, 'p' => $periodId]
        );
    }

    public function insertHistory(int $alertId, ?string $oldStatus, string $newStatus, ?string $oldPriority, ?string $newPriority, ?string $note, ?int $userId): void
    {
        $stmt = $this->db->prepare(Schema::isV2()
            ? "INSERT INTO alert_events (alert_id, event_type, old_status, new_status, old_priority, new_priority, note, created_by, created_at)
               VALUES (:a, 'state_change', :os, :ns, :op, :np, :note, :u, NOW())"
            : 'INSERT INTO alert_status_history (alert_id, old_status, new_status, old_priority, new_priority, note, changed_by, changed_at)
               VALUES (:a, :os, :ns, :op, :np, :note, :u, NOW())'
        );
        $stmt->execute(['a' => $alertId, 'os' => $oldStatus, 'ns' => $newStatus, 'op' => $oldPriority, 'np' => $newPriority, 'note' => $note, 'u' => $userId]);
    }

    public function history(int $alertId): array
    {
        return $this->fetchAllQuery(
            'SELECT h.*, u.full_name AS changed_by_name FROM ' . (Schema::isV2()
                ? "(SELECT id, alert_id, old_status, new_status, old_priority, new_priority, note, created_by AS changed_by, created_at AS changed_at
                    FROM alert_events WHERE event_type = 'state_change')"
                : 'alert_status_history') . ' h LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.alert_id = :a ORDER BY h.id',
            ['a' => $alertId]
        );
    }

    /** FN-07.02 - công tơ có chỉ số trong kỳ thuộc phạm vi rule (S6). */
    public function metersForRule(string $utility, string $scopeType, ?string $scopeValue, int $periodId): array
    {
        $params = ['u' => $utility, 'p' => $periodId];
        $filter = '';
        if ($scopeType === 'zone') {
            $filter = ' AND a.zone_id = :sv';
            $params['sv'] = (int) $scopeValue;
        } elseif ($scopeType === 'usage_type') {
            $filter = ' AND m.usage_type = :sv';
            $params['sv'] = (string) $scopeValue;
        }

        return $this->fetchAllQuery(
            "SELECT DISTINCT m.id AS meter_id, m.meter_code, m.usage_type, a.id AS apartment_id, a.code AS apartment_code
             FROM meters m JOIN apartments a ON a.id = m.apartment_id
             JOIN meter_readings r ON r.meter_id = m.id AND r.billing_period_id = :p
             WHERE m.utility_type = :u{$filter} ORDER BY a.id, m.id",
            $params
        );
    }

    /** FN-07.02 (chi phí) - căn có bản tính trong kỳ thuộc phạm vi rule (S5, S6). */
    public function apartmentsWithCharge(string $scopeType, ?string $scopeValue, int $periodId): array
    {
        $params = ['p' => $periodId];
        $filter = '';
        if ($scopeType === 'zone') {
            $filter = ' AND a.zone_id = :sv';
            $params['sv'] = (int) $scopeValue;
        }

        return $this->fetchAllQuery(
            "SELECT c.*, a.code AS apartment_code FROM apartment_charges c JOIN apartments a ON a.id = c.apartment_id
             WHERE c.billing_period_id = :p{$filter} ORDER BY a.id",
            $params
        );
    }

    // ------------------------------------------------------------------ [BA Proposal J-16] cảnh báo hệ thống (patch 005)

    /** Cảnh báo hệ thống loại $type của căn + kỳ (uq_alert_system_dedupe: tối đa 1). */
    public function findSystemAlert(string $type, int $apartmentId, int $periodId, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM alerts WHERE alert_type = :t AND apartment_id = :a AND billing_period_id = :p AND source = 'he_thong'"
            . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute(['t' => $type, 'a' => $apartmentId, 'p' => $periodId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Mọi cảnh báo hệ thống loại $type của kỳ (kể cả đã đóng) - dùng khi đánh giá lại. */
    public function systemAlertsOfPeriod(string $type, int $periodId): array
    {
        return $this->fetchAllQuery("SELECT * FROM alerts WHERE alert_type = :t AND billing_period_id = :p AND source = 'he_thong' ORDER BY id", ['t' => $type, 'p' => $periodId]);
    }

    /** Cảnh báo J-16 chưa được BQL kết luận gỡ chặn (conclusion NULL hoặc ngoài $resolving) của căn + kỳ. */
    public function blockingFor(string $type, array $resolving, int $apartmentId, int $periodId): ?array
    {
        $in = implode(',', array_map(fn (string $c): string => $this->db->quote($c), $resolving));
        $stmt = $this->db->prepare("SELECT id, status, conclusion FROM alerts WHERE alert_type = :t AND apartment_id = :a AND billing_period_id = :p
            AND (conclusion IS NULL OR conclusion NOT IN ({$in})) ORDER BY id LIMIT 1");
        $stmt->execute(['t' => $type, 'a' => $apartmentId, 'p' => $periodId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** apartment_id => alert_id các cảnh báo J-16 đang chặn xác nhận bản tính trong kỳ (cho danh sách tính phí). */
    public function blockingOfPeriod(string $type, array $resolving, int $periodId): array
    {
        $in = implode(',', array_map(fn (string $c): string => $this->db->quote($c), $resolving));

        return array_column($this->fetchAllQuery("SELECT apartment_id, MIN(id) AS alert_id FROM alerts WHERE alert_type = :t AND billing_period_id = :p
            AND (conclusion IS NULL OR conclusion NOT IN ({$in})) GROUP BY apartment_id", ['t' => $type, 'p' => $periodId]), 'alert_id', 'apartment_id');
    }

    /**
     * Chỉ số ĐÃ XÁC NHẬN của kỳ thuộc các căn đang hoạt động khai báo $declaredStatus (không dùng bản Nháp).
     * @return list<array<string,mixed>>
     */
    public function confirmedReadingsOfDeclared(string $declaredStatus, int $periodId): array
    {
        return $this->fetchAllQuery(
            "SELECT a.id AS apartment_id, a.code AS apartment_code, b.code AS building_code, a.occupancy_status,
                    m.id AS meter_id, m.meter_code, m.utility_type, m.status AS meter_status,
                    r.id AS reading_id, r.previous_reading, r.current_reading, r.consumption, r.status AS reading_status,
                    r.created_at AS entered_at, r.confirmed_at
             FROM apartments a
             JOIN buildings b ON b.id = a.building_id
             JOIN meters m ON m.apartment_id = a.id
             JOIN meter_readings r ON r.meter_id = m.id AND r.billing_period_id = :p AND r.status = 'da_xac_nhan'
             WHERE a.occupancy_status = :s AND a.status = 'hoat_dong'
             ORDER BY a.id, m.utility_type, m.id",
            ['p' => $periodId, 's' => $declaredStatus]
        );
    }
}
