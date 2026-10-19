<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AlertRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\DashboardRepository;

/**
 * FN-09.01 Dashboard quyết định - FR-11, US-04, BR-11, BR-37. CHỈ ĐỌC, chỉ ghép kết quả có sẵn:
 *   tiêu thụ / so với kỳ trước / baseline : FN-06 ConsumptionComparisonService, ScopeConsumptionService
 *   mục tiêu                              : FN-06 ConsumptionTargetService::tracking
 *   cảnh báo                               : FN-07 dữ liệu alerts (không đánh giá lại ngưỡng)
 *   khuyến nghị                            : FN-08 recommendations (không sinh khi tải trang)
 *   chi phí                                : FN-05 apartment_charges đã lưu (không tính lại)
 * Mỗi khối chỉ dựng khi role có quyền tương ứng. Điện và nước luôn tách riêng (không cộng kWh với m³).
 */
class DashboardService
{
    public const TREND_PERIODS = 6;

    private DashboardRepository $repo;
    private BillingPeriodRepository $periods;

    public function __construct()
    {
        $this->repo = new DashboardRepository();
        $this->periods = new BillingPeriodRepository();
    }

    public function filterOptions(?int $buildingId): array
    {
        return ['periods' => $this->periods->options(), 'buildings' => $this->repo->buildings(), 'zones' => $this->repo->zones($buildingId)];
    }

    /**
     * @param array{period_id:string,building_id:string,zone_id:string,utility_type:string} $f
     */
    public function build(array $f, array $user): array
    {
        $buildings = $this->repo->buildings();
        $buildingId = ctype_digit($f['building_id']) && in_array((int) $f['building_id'], array_map('intval', array_column($buildings, 'id')), true)
            ? (int) $f['building_id'] : (int) ($buildings[0]['id'] ?? 0);
        $zoneIds = array_map('intval', array_column($this->repo->zones($buildingId), 'id'));
        $zoneId = ctype_digit($f['zone_id']) && in_array((int) $f['zone_id'], $zoneIds, true) ? (int) $f['zone_id'] : null;
        $utility = array_key_exists($f['utility_type'], config('master_data.utility_type')) ? $f['utility_type'] : 'dien';

        $period = ctype_digit($f['period_id']) ? $this->periods->find((int) $f['period_id']) : null;
        $defaulted = false;
        if ($period === null) {
            $period = $this->repo->latestPeriodWithData(); // U3
            $defaulted = true;
        }
        $out = [
            'filters' => ['period_id' => (string) ($period['id'] ?? ''), 'building_id' => (string) $buildingId, 'zone_id' => (string) ($zoneId ?? ''), 'utility_type' => $utility],
            'period' => $period, 'defaulted' => $defaulted, 'has_data' => false,
            'scope' => $zoneId !== null ? ['type' => 'zone', 'id' => $zoneId] : ['type' => 'building', 'id' => $buildingId],
        ];
        if ($period === null || $buildingId === 0) {
            return $out;
        }
        $scopeType = $out['scope']['type'];
        $scopeId = $out['scope']['id'];
        $periodId = (int) $period['id'];

        // Tiêu thụ + so với kỳ trước / baseline (FN-06) - tách điện / nước
        $comparison = new ConsumptionComparisonService();
        $out['consumption'] = [];
        foreach (array_keys(config('master_data.utility_type')) as $u) {
            $dto = $comparison->compare($scopeType, $scopeId, $u, $periodId);
            if (!can('comparisons.view')) { // baseline chỉ BQL (FN-06 quyền)
                $dto = array_merge($dto, ['baseline_value' => null, 'difference' => null, 'percentage_change' => null, 'baseline_id' => null]);
            }
            $out['consumption'][$u] = $dto;
            $out['has_data'] = $out['has_data'] || $dto['current_value'] !== null;
        }

        // Xu hướng 6 kỳ (FN-06 measure) - 2 chuỗi riêng
        $measure = new ScopeConsumptionService();
        $out['trend'] = [];
        foreach ($this->repo->periodsUpTo((string) $period['start_date'], self::TREND_PERIODS) as $p) {
            foreach (array_keys(config('master_data.utility_type')) as $u) {
                $m = $measure->measure($scopeType, $scopeId, $u, $p);
                $out['trend'][$u][$p['period_code']] = $m['value'] === null ? null : $m['value'] / 100;
            }
        }

        // Theo căn trong phạm vi: top tiêu thụ (FN-06 measureAll - 2 query) và (BQL) chênh lệch baseline (FN-06 table)
        $apartments = $this->repo->apartmentsInScope($buildingId, $zoneId);
        $all = $measure->measureAll('apartment', $utility, $period);
        $top = [];
        foreach ($all as $aptId => $m) {
            if (isset($apartments[$aptId]) && $m['value'] !== null) {
                $top[] = ['apartment' => $apartments[$aptId], 'value' => $m['value']];
            }
        }
        usort($top, static fn ($a, $b) => $b['value'] <=> $a['value']);
        $out['top_consumers'] = array_slice($top, 0, 5);

        if (can('comparisons.view')) {
            $rows = array_values(array_filter($comparison->table($periodId, 'apartment', $utility)['rows'] ?? [], static fn ($r) => isset($apartments[$r['scope_id']])));
            $above = array_values(array_filter($rows, static fn ($r) => $r['difference'] !== null && bccomp($r['difference'], '0', 2) > 0));
            usort($above, static fn ($a, $b) => bccomp((string) ($b['percentage_change'] ?? '0'), (string) ($a['percentage_change'] ?? '0'), 2));
            $out['baseline'] = [
                'above' => count($above),
                'compared' => count(array_filter($rows, static fn ($r) => $r['difference'] !== null)),
                'no_baseline' => count(array_filter($rows, static fn ($r) => $r['current_value'] !== null && $r['baseline_value'] === null)),
                'top_increase' => array_slice($above, 0, 5),
            ];
        }

        // Mục tiêu (FN-06.04) - lọc đúng phạm vi đang xem
        if (can('targets.view')) {
            $tracking = (new ConsumptionTargetService())->tracking(['period_id' => (string) $periodId, 'utility_type' => '', 'scope_type' => '']);
            $rows = array_values(array_filter($tracking['rows'], fn ($t) => self::targetInScope($t, $buildingId, $zoneId, $zoneIds, $apartments)));
            $counts = ['dat' => 0, 'khong_dat' => 0, 'chua_du_du_lieu' => 0];
            foreach ($rows as $t) {
                $counts[$t['tracking']['target_status']]++;
            }
            $tracked = $counts['dat'] + $counts['khong_dat'];
            $out['targets'] = $counts + ['rows' => $rows, 'rate' => $tracked > 0 ? ScopeConsumptionService::percent($counts['dat'], $tracked) : null];
        }

        // Cảnh báo (FN-07) - Kỹ thuật chỉ phần của mình (K-18)
        if (can('alerts.view')) {
            $tech = ($user['role'] ?? '') === 'ky_thuat' ? (int) $user['id'] : null;
            $by = ['status' => [], 'priority' => [], 'utility' => []];
            $open = $high = $recheck = $j16 = 0;
            foreach ($this->repo->alertBreakdown($periodId, $buildingId, $zoneId, $tech) as $r) {
                $n = (int) $r['n'];
                $by['status'][$r['status']] = ($by['status'][$r['status']] ?? 0) + $n;
                if ($r['status'] !== 'da_dong') {
                    $open += $n;
                    $by['priority'][$r['priority']] = ($by['priority'][$r['priority']] ?? 0) + $n;
                    $uKey = alert_utility_key($r); // J-16 NULL -> 'both'; dữ liệu lạ -> 'unknown' (không dùng NULL/'' làm khoá)
                    $by['utility'][$uKey] = ($by['utility'][$uKey] ?? 0) + $n;
                    $high += $r['priority'] === 'cao' ? $n : 0;
                    $recheck += $r['status'] === 'can_kiem_tra_lai' ? $n : 0;
                    $j16 += ($r['alert_type'] ?? '') === config('j16.type') ? $n : 0; // [BA Proposal J-16]
                }
            }
            $filters = ['period_id' => (string) $periodId, 'utility_type' => '', 'zone_id' => (string) ($zoneId ?? ''), 'q' => '', 'source' => '', 'priority' => '', 'status' => 'open', 'rule_id' => '', 'building_id' => (string) $buildingId];
            $out['alerts'] = ['open' => $open, 'high' => $high, 'recheck' => $recheck, 'j16' => $j16, 'by' => $by, 'top' => (new AlertRepository())->search($filters, $tech, 8)];
        }

        if (can('recommendations.view')) {
            $out['recommendations'] = $this->repo->recommendationSummary($periodId, $buildingId, $zoneId);
        }

        // Chi phí (FN-05 đã lưu) - chính thức chỉ khi kỳ đã khoá (U2)
        if (can('billing.view')) {
            $cost = $this->repo->costSummary($periodId, $buildingId, $zoneId, config('billing.lockable_statuses'));
            $out['cost'] = $cost + ['official' => $period['status'] === 'da_khoa'];
        }

        return $out;
    }

    /** Mục tiêu so đúng phạm vi đang xem (mục tiêu căn chỉ khi căn thuộc phạm vi; mục tiêu tòa chỉ khi xem cả tòa). */
    private static function targetInScope(array $t, int $buildingId, ?int $zoneId, array $zoneIds, array $apartments): bool
    {
        return match ($t['scope_type']) {
            'apartment' => isset($apartments[(int) $t['scope_id']]),
            'zone' => $zoneId === null ? in_array((int) $t['scope_id'], $zoneIds, true) : (int) $t['scope_id'] === $zoneId,
            'building' => $zoneId === null && (int) $t['scope_id'] === $buildingId,
            default => false,
        };
    }
}
