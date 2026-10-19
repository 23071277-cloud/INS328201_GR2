<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AlertRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\ConsumptionTargetRepository;

/**
 * FN-08.02 - đánh giá TẤT ĐỊNH 1 rule khuyến nghị trên 1 cảnh báo (T1). Không AI, không ghi DB.
 * Dữ liệu lấy qua service/repository có sẵn: FN-06 ScopeConsumptionService::measure (CL đã xác nhận),
 * ConsumptionTargetService::track (mục tiêu), FN-07 alerts (cảnh báo cùng rule các kỳ trước). Không tính lại baseline/ngưỡng.
 * @return array{matched:bool, reason:string, evidence:array}
 */
class RecommendationRuleEvaluator
{
    private BillingPeriodRepository $periods;
    private ScopeConsumptionService $consumption;
    private AlertRepository $alerts;

    public function __construct()
    {
        $this->periods = new BillingPeriodRepository();
        $this->consumption = new ScopeConsumptionService();
        $this->alerts = new AlertRepository();
    }

    public function evaluate(array $rule, array $alert): array
    {
        $params = RecommendationRuleService::params($rule);
        if ($alert['utility_type'] === null && $rule['condition_type'] !== 'vuot_nguong_lien_tiep') {
            // [BA Proposal J-16] cảnh báo cấp căn gồm cả điện + nước: điều kiện theo 1 tiện ích không áp dụng được
            return self::no('Cảnh báo gồm cả điện + nước — điều kiện theo một tiện ích không áp dụng');
        }
        if (isset($params['utility_type']) && $params['utility_type'] !== $alert['utility_type']) {
            return self::no('Quy tắc chỉ áp cho ' . (config('alerts.utility_word')[$params['utility_type']] ?? $params['utility_type']));
        }

        return match ($rule['condition_type']) {
            'cl_zero_n_ky' => $this->clZero($alert, (int) ($params['n_periods'] ?? 0)),
            'vuot_nguong_lien_tiep' => $this->consecutiveAlerts($alert, (int) ($params['n_periods'] ?? 0)),
            'khong_dat_muc_tieu' => $this->targetMissed($alert),
            default => self::no('Loại điều kiện không hỗ trợ'),
        };
    }

    /** CL = 0 ở kỳ cảnh báo và N−1 kỳ liền trước, đúng phạm vi cảnh báo (công tơ / căn). */
    private function clZero(array $alert, int $n): array
    {
        if ($n < 1) {
            return self::no('Thiếu tham số N');
        }
        $scopeType = $alert['meter_id'] !== null ? 'meter' : 'apartment';
        $scopeId = (int) ($alert['meter_id'] ?? $alert['apartment_id']);
        $evidence = [];
        foreach ($this->chain((int) $alert['billing_period_id'], $n) as $period) {
            if ($period === null) {
                return self::no("Không đủ {$n} kỳ liên tiếp", $evidence);
            }
            $m = $this->consumption->measure($scopeType, $scopeId, $alert['utility_type'], $period);
            if ($m['status'] !== 'ok') {
                return self::no("Kỳ {$period['period_code']}: chưa đủ dữ liệu đã xác nhận", $evidence); // không coi thiếu = 0
            }
            $evidence[] = ['period' => $period['period_code'], 'cl' => ScopeConsumptionService::fmt($m['value'])];
            if ($m['value'] !== 0) {
                return self::no("Kỳ {$period['period_code']}: CL ≠ 0", $evidence);
            }
        }

        return ['matched' => true, 'reason' => "CL = 0 trong {$n} kỳ liên tiếp", 'evidence' => $evidence];
    }

    /** Cảnh báo do rule FN-07 và cùng rule đã sinh cảnh báo cho cùng căn + công tơ ở N kỳ liên tiếp. */
    private function consecutiveAlerts(array $alert, int $n): array
    {
        if ($n < 1) {
            return self::no('Thiếu tham số N');
        }
        if ($alert['alert_rule_id'] === null) {
            return self::no('Cảnh báo thủ công — không gắn quy tắc ngưỡng');
        }
        $evidence = [];
        foreach ($this->chain((int) $alert['billing_period_id'], $n) as $period) {
            if ($period === null) {
                return self::no("Không đủ {$n} kỳ liên tiếp", $evidence);
            }
            $exists = $this->alerts->existsFor((int) $alert['alert_rule_id'], (int) $alert['apartment_id'], (int) ($alert['meter_id'] ?? 0), (int) $period['id']);
            $evidence[] = ['period' => $period['period_code'], 'alert_rule_id' => (int) $alert['alert_rule_id'], 'exceeded' => $exists];
            if (!$exists) {
                return self::no("Kỳ {$period['period_code']}: không vượt ngưỡng quy tắc #{$alert['alert_rule_id']}", $evidence);
            }
        }

        return ['matched' => true, 'reason' => "Vượt ngưỡng quy tắc #{$alert['alert_rule_id']} trong {$n} kỳ liên tiếp", 'evidence' => $evidence];
    }

    /** Mục tiêu căn hộ (cùng tiện ích, kỳ) tồn tại và FN-06 track() = Không đạt. Không có mục tiêu -> không thoả. */
    private function targetMissed(array $alert): array
    {
        $target = (new ConsumptionTargetRepository())->findFor('apartment', (int) $alert['apartment_id'], $alert['utility_type'], (int) $alert['billing_period_id']);
        if ($target === null) {
            return self::no('Căn hộ không có mục tiêu cho tiện ích / kỳ này');
        }
        $track = (new ConsumptionTargetService())->track((int) $target['id']);
        $evidence = [['target_id' => (int) $target['id'], 'target' => $track['target_value'], 'actual' => $track['actual_value'], 'status' => $track['target_status']]];

        return $track['target_status'] === 'khong_dat'
            ? ['matched' => true, 'reason' => "Không đạt mục tiêu (thực tế {$track['actual_value']} > mục tiêu {$track['target_value']})", 'evidence' => $evidence]
            : self::no('Mục tiêu: ' . (config('analytics.target_status')[$track['target_status']] ?? $track['target_status']), $evidence);
    }

    /** Kỳ cảnh báo + các kỳ liền trước (null nếu thiếu). */
    private function chain(int $periodId, int $n): array
    {
        $out = [];
        $period = $this->periods->find($periodId);
        for ($i = 0; $i < $n; $i++) {
            $out[] = $period;
            $period = $period !== null ? $this->periods->previousOf((string) $period['start_date']) : null;
        }

        return $out;
    }

    private static function no(string $reason, array $evidence = []): array
    {
        return ['matched' => false, 'reason' => $reason, 'evidence' => $evidence];
    }
}
