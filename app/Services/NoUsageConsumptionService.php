<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLogger;
use App\Core\BaseService;
use App\Core\Decimal;
use App\Core\ValidationException;
use App\Repositories\AlertRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\SystemConfigRepository;
use Throwable;

/**
 * [BA Proposal / Assumption J-16] "Status–Consumption Consistency Verification" - NOT ORIGINAL CONFIRMED REQUIREMENT.
 * Phát hiện: căn ĐANG HOẠT ĐỘNG khai báo occupancy_status = 'khong_phat_sinh' (BQL khai báo = dữ liệu nghiệp vụ, A-12)
 * nhưng chỉ số ĐÃ XÁC NHẬN của kỳ có CL > 0 (điện hoặc nước) -> 1 cảnh báo hệ thống / (căn, kỳ) để CON NGƯỜI xác minh.
 * Là "bất nhất dữ liệu / nghiệp vụ", KHÔNG kết luận nguyên nhân; không đổi trạng thái căn, chỉ số, phí.
 *
 * Chống trùng: uq_alert_system_dedupe (alert_type, apartment_id, billing_period_id) + khoá FOR UPDATE.
 * Đánh giá lại (sau duyệt chỉ số / BQL "Đánh giá ngưỡng"): cảnh báo đang mở -> cập nhật snapshot + lịch sử nếu số liệu đổi;
 * CL về 0 hoặc căn không còn khai báo "không phát sinh" -> chỉ ghi nhận, GIỮ cho BQL xác nhận đóng (không tự đóng / xoá).
 * Cảnh báo đã đóng: không tự mở lại (BQL mở lại nếu cần).
 */
class NoUsageConsumptionService extends BaseService
{
    private AlertRepository $alerts;
    private BillingPeriodRepository $periods;

    public function __construct()
    {
        $this->alerts = new AlertRepository();
        $this->periods = new BillingPeriodRepository();
    }

    public static function type(): string
    {
        return (string) config('j16.type');
    }

    public static function isJ16(array $alert): bool
    {
        return ($alert['alert_type'] ?? '') === self::type();
    }

    /** Kết luận gỡ chặn xác nhận bản tính (A/B/C). */
    public static function resolvingConclusions(): array
    {
        return array_keys(array_filter(config('j16.conclusions'), static fn (array $c): bool => $c['resolves']));
    }

    /** Nhãn tiện ích của cảnh báo: NULL = "Điện + Nước" CHỈ với J-16; cảnh báo FN-07 giữ nhãn cũ. */
    public static function utilityLabel(array $alert): string
    {
        if (self::isJ16($alert) && ($alert['utility_type'] ?? null) === null) {
            return 'Điện + Nước';
        }

        return label_of('utility_type', $alert['utility_type'] ?? null);
    }

    /**
     * Đánh giá cả kỳ. Không ném lỗi nghiệp vụ ra ngoài cho trigger tự động (gọi qua evaluateQuietly).
     * @return array{created:int[], updated:int[], unchanged:int, noted:int[], evaluated:int}
     */
    public function evaluatePeriod(int $periodId, string $trigger): array
    {
        $period = $this->periods->find($periodId);
        if ($period === null) {
            throw ValidationException::withMessage('Kỳ không tồn tại.');
        }
        $summary = ['created' => [], 'updated' => [], 'unchanged' => 0, 'noted' => [], 'evaluated' => 0];

        $byApartment = [];
        foreach ($this->alerts->confirmedReadingsOfDeclared((string) config('j16.declared_status'), $periodId) as $r) {
            $byApartment[(int) $r['apartment_id']][] = $r;
        }
        $detected = [];
        foreach ($byApartment as $apartmentId => $readings) {
            $summary['evaluated']++;
            $snapshot = $this->snapshot($period, $readings, $trigger);
            if ($snapshot['electricity_consumption'] === '0.00' && $snapshot['water_consumption'] === '0.00') {
                continue; // Case 3: cả hai CL = 0 -> không cảnh báo
            }
            $detected[$apartmentId] = true;
            $result = $this->upsert($period, $apartmentId, $snapshot);
            if ($result['action'] === 'created') {
                $summary['created'][] = $result['id'];
            } elseif ($result['action'] === 'updated') {
                $summary['updated'][] = $result['id'];
            } else {
                $summary['unchanged']++;
            }
        }

        // Cảnh báo đang mở của kỳ không còn thoả điều kiện (CL về 0 / căn đổi khai báo) -> chỉ ghi nhận, chờ BQL
        foreach ($this->alerts->systemAlertsOfPeriod(self::type(), $periodId) as $alert) {
            if (isset($detected[(int) $alert['apartment_id']]) || $alert['status'] === 'da_dong') {
                continue;
            }
            if ($this->noteNoLongerMatching($period, $alert, $byApartment[(int) $alert['apartment_id']] ?? [], $trigger)) {
                $summary['noted'][] = (int) $alert['id'];
            }
        }

        AuditLogger::log('EVALUATE_NO_USAGE_CONSUMPTION', 'billing_period', (string) $periodId, null, [
            'trigger' => $trigger, 'rule' => config('j16.rule'), 'evaluated' => $summary['evaluated'],
            'created' => $summary['created'], 'updated' => $summary['updated'], 'noted' => $summary['noted'],
        ]);

        return $summary;
    }

    /** Trigger tự động (sau duyệt chỉ số): lỗi J-16 không được làm hỏng thao tác chính đã commit. */
    public function evaluateQuietly(int $periodId, string $trigger): ?array
    {
        try {
            return $this->evaluatePeriod($periodId, $trigger);
        } catch (Throwable $e) {
            error_log(' đánh giá kỳ ' . $periodId . ' lỗi: ' . $e->getMessage());

            return null;
        }
    }

    /** Cảnh báo J-16 đang chặn xác nhận bản tính của căn + kỳ (null = không chặn). */
    public function blockingAlert(int $apartmentId, int $periodId): ?array
    {
        return $this->alerts->blockingFor(self::type(), self::resolvingConclusions(), $apartmentId, $periodId);
    }

    /** @return array<int,int> apartment_id => alert_id đang chặn trong kỳ */
    public function blockingOfPeriod(int $periodId): array
    {
        return array_map('intval', $this->alerts->blockingOfPeriod(self::type(), self::resolvingConclusions(), $periodId));
    }

    /**
     * [ASSUMPTION J-16-P] GỢI Ý ưu tiên theo CL (system_configs j16_*). Chỉ dùng lúc tạo; BQL luôn đổi được.
     * Cao nếu một tiện ích ≥ ngưỡng cao; Thấp nếu mọi tiện ích có CL > 0 đều < ngưỡng thấp; còn lại Trung bình.
     * Cấu hình thiếu / không hợp lệ -> Trung bình kèm ghi chú (không đoán số).
     * @return array{level:string, basis:string}
     */
    public static function suggestPriority(string $electricity, string $water, ?array $configs = null): array
    {
        try {
            $configs ??= (new SystemConfigRepository())->allValues();
        } catch (Throwable $e) {
            $configs = [];
        }
        $values = ['dien' => $electricity, 'nuoc' => $water];
        $levels = [];
        $basis = [];
        foreach (config('j16.priority_keys') as $utility => $keys) {
            if (bccomp($values[$utility], '0', 2) <= 0) {
                continue;
            }
            $low = $configs[$keys['low']] ?? null;
            $high = $configs[$keys['high']] ?? null;
            if (!is_string($low) || !is_string($high) || Decimal::toCents($low) === null || Decimal::toCents($high) === null) {
                return ['level' => 'trung_binh', 'basis' => 'Thiếu / sai cấu hình ' . $keys['low'] . ' / ' . $keys['high'] . ' — mặc định Trung bình'];
            }
            $unit = config('pricing.utility_unit')[$utility];
            if (bccomp($values[$utility], $high, 2) >= 0) {
                $levels[] = 'cao';
                $basis[] = "CL {$unit} " . format_decimal($values[$utility]) . " ≥ {$high}";
            } elseif (bccomp($values[$utility], $low, 2) < 0) {
                $levels[] = 'thap';
                $basis[] = "CL {$unit} " . format_decimal($values[$utility]) . " < {$low}";
            } else {
                $levels[] = 'trung_binh';
                $basis[] = "CL {$unit} " . format_decimal($values[$utility]) . " trong [{$low}, {$high})";
            }
        }
        $level = in_array('cao', $levels, true) ? 'cao' : ($levels !== [] && array_unique($levels) === ['thap'] ? 'thap' : 'trung_binh');

        return ['level' => $level, 'basis' => 'Gợi ý: ' . implode('; ', $basis) . ' — BQL quyết định ưu tiên cuối.'];
    }

    // ------------------------------------------------------------------

    /** Snapshot giải thích (lưu alerts.snapshot_json). Tổng CL theo tiện ích chỉ từ chỉ số đã xác nhận. */
    private function snapshot(array $period, array $readings, string $trigger): array
    {
        $cents = ['dien' => 0, 'nuoc' => 0];
        $meters = [];
        foreach ($readings as $r) {
            $cents[$r['utility_type']] += (int) Decimal::toCents((string) $r['consumption']);
            $meters[] = [
                'meter_id' => (int) $r['meter_id'], 'meter_code' => $r['meter_code'], 'utility_type' => $r['utility_type'],
                'reading_id' => (int) $r['reading_id'], 'previous_reading' => $r['previous_reading'], 'current_reading' => $r['current_reading'],
                'consumption' => $r['consumption'], 'reading_status' => $r['reading_status'], 'entered_at' => $r['entered_at'], 'confirmed_at' => $r['confirmed_at'],
            ];
        }
        $first = $readings[0];

        return [
            'rule' => config('j16.rule'),
            'source' => config('j16.source'),
            'tag' => config('j16.tag'),
            'apartment_id' => (int) $first['apartment_id'],
            'apartment_code' => $first['apartment_code'],
            'building_code' => $first['building_code'],
            'period_id' => (int) $period['id'],
            'period_code' => $period['period_code'],
            'declared_usage_status' => $first['occupancy_status'],
            'electricity_consumption' => Decimal::fromCents($cents['dien']),
            'water_consumption' => Decimal::fromCents($cents['nuoc']),
            'meters' => $meters,
            'evaluated_at' => date('Y-m-d H:i:s'),
            'trigger' => $trigger,
        ];
    }

    /** J-16: chỉ điện > 0 -> 'dien'; chỉ nước -> 'nuoc'; cả hai -> NULL (= "Điện + Nước", chỉ với loại này). */
    private static function utilityOf(array $snapshot): ?string
    {
        $e = bccomp($snapshot['electricity_consumption'], '0', 2) > 0;
        $w = bccomp($snapshot['water_consumption'], '0', 2) > 0;

        return $e && $w ? null : ($e ? 'dien' : 'nuoc');
    }

    /** @return array{action:string, id:int} created | updated | unchanged */
    private function upsert(array $period, int $apartmentId, array $snapshot): array
    {
        return $this->transaction($this->alerts, function () use ($period, $apartmentId, $snapshot): array {
            $existing = $this->alerts->findSystemAlert(self::type(), $apartmentId, (int) $period['id'], true);
            $utility = self::utilityOf($snapshot);
            $actual = $utility === null ? null : ($utility === 'dien' ? $snapshot['electricity_consumption'] : $snapshot['water_consumption']);

            if ($existing === null) {
                $suggest = self::suggestPriority($snapshot['electricity_consumption'], $snapshot['water_consumption']);
                $snapshot += ['detected_at' => $snapshot['evaluated_at'], 'priority_suggestion' => $suggest];
                $row = [
                    'alert_type' => self::type(), 'alert_rule_id' => null, 'meter_id' => null, 'apartment_id' => $apartmentId,
                    'billing_period_id' => (int) $period['id'], 'utility_type' => $utility, 'source' => 'he_thong',
                    'actual_value' => $actual, 'compare_value' => '0', 'percent_change' => null,
                    'priority' => $suggest['level'], 'status' => 'moi', 'description' => (string) config('j16.message'),
                    'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'created_by' => null,
                ];
                $id = $this->alerts->insertUnlessDuplicate($row);
                if ($id === null) { // tạo đồng thời: bản kia thắng (UNIQUE) -> coi như đã có
                    return ['action' => 'unchanged', 'id' => 0];
                }
                $this->alerts->insertHistory($id, null, 'moi', null, $suggest['level'], 'Hệ thống phát hiện (' . config('j16.rule') . '): CL điện '
                    . format_decimal($snapshot['electricity_consumption']) . ', CL nước ' . format_decimal($snapshot['water_consumption']) . '. ' . $suggest['basis'], null);
                $this->audit('DETECT_NO_USAGE_CONSUMPTION', 'alert', $id, null, ['snapshot' => $snapshot] + array_diff_key($row, ['snapshot_json' => true]));

                return ['action' => 'created', 'id' => $id];
            }

            $old = json_decode((string) $existing['snapshot_json'], true) ?: [];
            if ($existing['status'] === 'da_dong' || self::fingerprint($old) === self::fingerprint($snapshot)) {
                return ['action' => 'unchanged', 'id' => (int) $existing['id']];
            }
            $snapshot += ['detected_at' => $old['detected_at'] ?? $existing['created_at'], 'priority_suggestion' => $old['priority_suggestion'] ?? null,
                'previous_values' => ['electricity_consumption' => $old['electricity_consumption'] ?? null, 'water_consumption' => $old['water_consumption'] ?? null]];
            // Ưu tiên KHÔNG đổi khi đánh giá lại (BQL quyết định)
            $this->alerts->updateById((int) $existing['id'], ['utility_type' => $utility, 'actual_value' => $actual, 'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
            $this->alerts->insertHistory((int) $existing['id'], $existing['status'], $existing['status'], null, null, 'Đánh giá lại: CL điện '
                . format_decimal($old['electricity_consumption'] ?? '0') . ' → ' . format_decimal($snapshot['electricity_consumption']) . ', CL nước '
                . format_decimal($old['water_consumption'] ?? '0') . ' → ' . format_decimal($snapshot['water_consumption']) . ' (' . $snapshot['trigger'] . ')', null);
            $this->audit('DETECT_NO_USAGE_CONSUMPTION', 'alert', (int) $existing['id'], ['snapshot' => $old], ['snapshot' => $snapshot, 're_evaluated' => true]);

            return ['action' => 'updated', 'id' => (int) $existing['id']];
        });
    }

    /** CL về 0 / căn không còn khai báo "không phát sinh": ghi nhận 1 lần, KHÔNG tự đóng (ưu tiên human review). */
    private function noteNoLongerMatching(array $period, array $alert, array $readings, string $trigger): bool
    {
        return $this->transaction($this->alerts, function () use ($period, $alert, $readings, $trigger): bool {
            $locked = $this->alerts->findForUpdate((int) $alert['id']);
            $old = json_decode((string) $locked['snapshot_json'], true) ?: [];
            if (($old['data_no_longer_matches'] ?? false) === true) {
                return false;
            }
            $reason = $readings === [] ? 'Căn không còn khai báo "Không phát sinh điện nước" hoặc không còn chỉ số đã xác nhận trong kỳ'
                : 'CL đã xác nhận hiện tại = 0';
            $snapshot = ($readings !== [] ? $this->snapshot($period, $readings, $trigger) : $old) + $old;
            $snapshot['data_no_longer_matches'] = true;
            $snapshot['data_no_longer_matches_reason'] = $reason;
            $snapshot['evaluated_at'] = date('Y-m-d H:i:s');
            $this->alerts->updateById((int) $alert['id'], ['snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
            $this->alerts->insertHistory((int) $alert['id'], $locked['status'], $locked['status'], null, null,
                'Đánh giá lại: ' . $reason . ' — giữ lịch sử, chờ BQL xác nhận đóng (không tự đóng).', null);
            $this->audit('DETECT_NO_USAGE_CONSUMPTION', 'alert', (int) $alert['id'], null, ['data_no_longer_matches' => true, 'reason' => $reason, 'trigger' => $trigger]);

            return true;
        });
    }

    private static function fingerprint(array $s): string
    {
        $meters = array_map(static fn (array $m): string => $m['reading_id'] . ':' . $m['consumption'], $s['meters'] ?? []);

        return ($s['electricity_consumption'] ?? '') . '|' . ($s['water_consumption'] ?? '') . '|' . ($s['declared_usage_status'] ?? '') . '|' . implode(',', $meters);
    }
}
