<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ApartmentChargeRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\ConsumptionAnalyticsRepository;

/**
 * FN-06.02 So sánh tiêu thụ với kỳ trước / baseline - BR-27, BR-26, BR-11. Chỉ đọc, KHÔNG sinh cảnh báo
 * hay kết luận bất thường (BR-29 - FN-07).
 *   difference           = hiện tại − baseline
 *   percentage_change    = difference ÷ baseline × 100      (baseline = 0 -> null, R11)
 *   previous_difference  = hiện tại − kỳ trước;  previous_percentage_change tương tự
 * Chỉ tính khi số đo hiện tại 'ok' (đủ dữ liệu đã xác nhận); kỳ trước / baseline thiếu -> null kèm trạng thái.
 * compare() trả mảng chuẩn hoá để FN-07 dùng lại (R14).
 */
class ConsumptionComparisonService
{
    private BillingPeriodRepository $periods;
    private ConsumptionAnalyticsRepository $analytics;
    private ScopeConsumptionService $consumption;
    private BaselineService $baselines;

    public function __construct()
    {
        $this->periods = new BillingPeriodRepository();
        $this->analytics = new ConsumptionAnalyticsRepository();
        $this->consumption = new ScopeConsumptionService($this->analytics);
        $this->baselines = new BaselineService();
    }

    /** DTO so sánh cho 1 phạm vi (FN-07 gọi lại hàm này). */
    public function compare(string $scopeType, int $scopeId, string $utility, int $periodId): ?array
    {
        $period = $this->periods->find($periodId);
        if ($period === null) {
            return null;
        }
        $previous = $this->periods->previousOf((string) $period['start_date']);

        return self::build(
            $scopeType, $scopeId, $utility, $period, $previous,
            $this->consumption->measure($scopeType, $scopeId, $utility, $period),
            $previous !== null ? $this->consumption->measure($scopeType, $scopeId, $utility, $previous) : null,
            $this->baselines->effective($scopeType, $scopeId, $utility, (string) $period['end_date'])
        );
    }

    /**
     * Bảng so sánh mọi thực thể của một loại phạm vi trong kỳ (màn hình FN-06.02), kèm chi phí tham khảo (R12).
     * @return array{period:array, previous:?array, rows:array}
     */
    public function table(int $periodId, string $scopeType, string $utility): ?array
    {
        $period = $this->periods->find($periodId);
        if ($period === null) {
            return null;
        }
        $previous = $this->periods->previousOf((string) $period['start_date']);
        $current = $this->consumption->measureAll($scopeType, $utility, $period);
        $prior = $previous !== null ? $this->consumption->measureAll($scopeType, $utility, $previous) : [];
        $baselines = $this->baselines->effectiveMap($scopeType, $utility, (string) $period['end_date']);
        $cost = $this->cost($scopeType, $utility, $period);
        $costPrev = $previous !== null ? $this->cost($scopeType, $utility, $previous) : [];

        $rows = [];
        foreach ($this->analytics->scopeEntities($scopeType) as $entity) {
            if ($scopeType === 'meter' && $entity['utility_type'] !== $utility) {
                continue;
            }
            $id = (int) $entity['id'];
            $dto = self::build($scopeType, $id, $utility, $period, $previous,
                $current[$id] ?? ScopeConsumptionService::emptyResult(),
                $previous !== null ? ($prior[$id] ?? ScopeConsumptionService::emptyResult()) : null,
                $baselines[$id] ?? null);
            $dto['scope_label'] = $entity['label'];
            $dto['scope_status'] = $entity['status'];
            $dto['current_cost'] = $cost[$id] ?? null;
            $dto['previous_cost'] = $costPrev[$id] ?? null;
            $rows[] = $dto;
        }

        return ['period' => $period, 'previous' => $previous, 'rows' => $rows];
    }

    public static function build(string $scopeType, int $scopeId, string $utility, array $period, ?array $previous, array $current, ?array $prior, ?array $baseline): array
    {
        $cur = $current['value'];
        $prev = $prior !== null ? $prior['value'] : null;
        $base = $baseline['value_cents'] ?? null;
        $comparable = $cur !== null;

        $difference = $comparable && $base !== null ? $cur - $base : null;
        $prevDifference = $comparable && $prev !== null ? $cur - $prev : null;

        if (!$comparable) {
            $message = $current['status'] === 'no_data'
                ? 'Chưa đủ dữ liệu lịch sử để so sánh cho phạm vi này.' // BR-26 [ĐX]
                : (config('analytics.data_status')[$current['status']] ?? $current['status']);
        } elseif ($base === null) {
            $message = 'Chưa có baseline hiệu lực — chỉ so với kỳ trước.';
        } elseif ($base === 0) {
            $message = 'Baseline = 0 — không xác định được % thay đổi.';
        } else {
            $message = null;
        }

        return [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'utility_type' => $utility,
            'period_id' => (int) $period['id'],
            'period_code' => $period['period_code'],
            'data_status' => $current['status'],
            'current_value' => ScopeConsumptionService::fmt($cur),
            'previous_period_code' => $previous['period_code'] ?? null,
            'previous_status' => $prior['status'] ?? null,
            'previous_value' => ScopeConsumptionService::fmt($prev),
            'baseline_id' => isset($baseline['id']) ? (int) $baseline['id'] : null,
            'baseline_method' => $baseline['method'] ?? null,
            'baseline_effective_date' => $baseline['effective_date'] ?? null,
            'baseline_value' => ScopeConsumptionService::fmt($base),
            'difference' => ScopeConsumptionService::fmt($difference),
            'percentage_change' => $difference !== null ? ScopeConsumptionService::percent($difference, $base) : null,
            'previous_difference' => ScopeConsumptionService::fmt($prevDifference),
            'previous_percentage_change' => $prevDifference !== null ? ScopeConsumptionService::percent($prevDifference, $prev) : null,
            'trend' => $difference === null ? null : ($difference > 0 ? 'up' : ($difference < 0 ? 'down' : 'equal')),
            'message' => $message,
        ];
    }

    /**
     * Chi phí tham khảo (R12): từ apartment_charges ĐÃ XÁC NHẬN trở lên; điện = TT + VAT, nước = TT.
     * Không áp dụng cho phạm vi công tơ. @return array<int,string> scope_id => tiền
     */
    private function cost(string $scopeType, string $utility, array $period): array
    {
        if ($scopeType === 'meter') {
            return [];
        }
        $rows = (new ApartmentChargeRepository())->aggregate((int) $period['id'], $scopeType, config('billing.lockable_statuses'));
        $out = [];
        foreach ($rows as $row) {
            if ($row['group_id'] !== null) {
                $out[(int) $row['group_id']] = $utility === 'dien' ? $row['electricity_cost'] : $row['water_cost'];
            }
        }

        return $out;
    }
}
