<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLogger;
use App\Core\BaseService;
use App\Core\Decimal;
use App\Core\ValidationException;
use App\Repositories\AlertRepository;
use App\Repositories\AlertRuleRepository;
use App\Repositories\BillingPeriodRepository;

/**
 * FN-07.02 Tự động đánh giá ngưỡng & sinh cảnh báo - BR-28, BR-26, BR-29, BR-40, BR-43.
 * KHÔNG tự tính tiêu thụ / baseline / %: lấy DTO của FN-06 (ConsumptionComparisonService::compare) - J-20 nằm ở FN-06.
 * Chi phí: lấy bản tính FN-05 đã xác nhận + hiện hành (S5). So sánh ngưỡng: RuleConditionEvaluator (generic).
 * Mỗi cảnh báo: 1 transaction (kiểm tra trùng -> thêm -> lịch sử -> audit snapshot); UNIQUE uq_alert_dedupe là lớp chặn cuối.
 * Không sinh khuyến nghị (FN-08). Không chạy nền - BQL kích hoạt theo kỳ (S10, J-22).
 */
class AlertEvaluationService extends BaseService
{
    private AlertRepository $alerts;
    private AlertRuleRepository $rules;
    private BillingPeriodRepository $periods;
    private ConsumptionComparisonService $comparison;
    private BaselineService $baselines;
    private ?BillingCalculationService $billing = null;
    /** @var array<int,bool> baseline_id => nguồn đã đổi (R9 FN-06) */
    private array $staleBaselines = [];

    public function __construct()
    {
        $this->alerts = new AlertRepository();
        $this->rules = new AlertRuleRepository();
        $this->periods = new BillingPeriodRepository();
        $this->comparison = new ConsumptionComparisonService();
        $this->baselines = new BaselineService();
    }

    /**
     * Đánh giá mọi rule đang Bật (hoặc 1 rule) cho 1 kỳ.
     * @param bool $dryRun true = chạy thử ([ĐX] FN-07.01 mục 6), không ghi gì
     * @return array{created:int[], would_create:array, duplicates:int, skipped:array<string,int>, evaluated:int, rules:int}
     */
    public function evaluatePeriod(int $periodId, ?int $ruleId = null, bool $dryRun = false): array
    {
        $period = $this->periods->find($periodId);
        if ($period === null) {
            throw ValidationException::withMessage('Kỳ không tồn tại.');
        }
        $this->staleBaselines = []; // trạng thái nguồn baseline có thể đổi giữa 2 lần đánh giá
        $rules = $this->rules->activeRules($ruleId);
        if ($ruleId !== null && $rules === [] && !$dryRun) {
            throw ValidationException::withMessage('Quy tắc không tồn tại hoặc đang Tắt — không dùng để đánh giá.');
        }
        if ($ruleId !== null && $dryRun && $rules === []) {
            $rule = $this->rules->find($ruleId); // chạy thử được cả rule đang Tắt
            $rules = $rule !== null ? [$rule] : [];
        }

        $summary = ['created' => [], 'would_create' => [], 'duplicates' => 0, 'skipped' => [], 'evaluated' => 0, 'rules' => count($rules)];
        foreach ($rules as $rule) {
            $candidates = $rule['measure_type'] === 'chi_phi' ? $this->costCandidates($rule, $period) : $this->consumptionCandidates($rule, $period);
            foreach ($candidates as $c) {
                $summary['evaluated']++;
                if ($c['result'] !== 'match') {
                    $summary['skipped'][$c['reason']] = ($summary['skipped'][$c['reason']] ?? 0) + 1;
                    continue;
                }
                if ($dryRun) {
                    $summary['would_create'][] = $c;
                    continue;
                }
                $id = $this->createAlert($rule, $period, $c);
                if ($id === null) {
                    $summary['duplicates']++;
                } else {
                    $summary['created'][] = $id;
                }
            }
        }

        if (!$dryRun && $ruleId === null) {
            $summary['j16'] = (new NoUsageConsumptionService())->evaluatePeriod($periodId, 'evaluate_alerts'); // [BA Proposal J-16]
        }
        if (!$dryRun) {
            AuditLogger::log('EVALUATE_ALERT_RULES', 'billing_period', (string) $periodId, null, [
                'rule_id' => $ruleId, 'rules' => $summary['rules'], 'evaluated' => $summary['evaluated'],
                'created' => count($summary['created']), 'duplicates' => $summary['duplicates'], 'skipped' => $summary['skipped'],
            ]);
        }

        return $summary;
    }

    /**
     * Đánh giá 1 DTO so sánh FN-06 theo 1 rule tiêu thụ (thuần, không đọc DB ngoài cờ baseline cũ).
     * @return array{result:string, reason:string, actual:?string, compare:?string, percent:?string}
     *   result: match | no_match | skip
     */
    public function evaluateConsumption(array $rule, array $dto): array
    {
        $skip = static fn (string $reason): array => ['result' => 'skip', 'reason' => $reason, 'actual' => $dto['current_value'], 'compare' => null, 'percent' => null];
        if ($dto['data_status'] !== 'ok') {
            return $skip('Chưa đủ dữ liệu đã xác nhận (' . $dto['data_status'] . ')');
        }

        switch ($rule['compare_base']) {
            case 'gia_tri_tuyet_doi':
                $measure = $dto['current_value'];
                $compare = (string) $rule['threshold_value'];
                $percent = null;
                break;
            case 'ky_truoc':
                if ($dto['previous_value'] === null) {
                    return $skip('Không có dữ liệu kỳ trước');
                }
                if ($dto['previous_percentage_change'] === null) {
                    return $skip('% so kỳ trước không xác định (kỳ trước = 0)');
                }
                $measure = $percent = $dto['previous_percentage_change'];
                $compare = $dto['previous_value'];
                break;
            case 'baseline':
                if ($dto['baseline_value'] === null) {
                    return $skip('Không có baseline hiệu lực');
                }
                if ($this->baselineStale((int) $dto['baseline_id'])) {
                    return $skip('Baseline có dữ liệu nguồn đã thay đổi — không dùng');
                }
                if ($dto['percentage_change'] === null) {
                    return $skip('% so baseline không xác định (baseline = 0)');
                }
                $measure = $percent = $dto['percentage_change'];
                $compare = $dto['baseline_value'];
                break;
            default:
                return $skip('Mốc so sánh không hỗ trợ');
        }

        $match = RuleConditionEvaluator::matches($rule['operator'], $measure, (string) $rule['threshold_value']);

        return ['result' => $match ? 'match' : 'no_match', 'reason' => $match ? '' : 'Trong ngưỡng', 'actual' => $dto['current_value'], 'compare' => $compare, 'percent' => $percent];
    }

    // ------------------------------------------------------------------

    private function consumptionCandidates(array $rule, array $period): array
    {
        $out = [];
        foreach ($this->alerts->metersForRule($rule['utility_type'], $rule['scope_type'], $rule['scope_value'], (int) $period['id']) as $meter) {
            $dto = $this->comparison->compare('meter', (int) $meter['meter_id'], $rule['utility_type'], (int) $period['id']); // FN-06
            $out[] = $this->evaluateConsumption($rule, $dto) + [
                'apartment_id' => (int) $meter['apartment_id'], 'apartment_code' => $meter['apartment_code'],
                'meter_id' => (int) $meter['meter_id'], 'meter_code' => $meter['meter_code'], 'dto' => $dto,
            ];
        }

        return $out;
    }

    /** Chi phí theo căn (S5): bản tính Đã xác nhận trở lên và hiện hành (FN-05 Q12); kỳ trước cũng phải hợp lệ. */
    private function costCandidates(array $rule, array $period): array
    {
        $this->billing ??= new BillingCalculationService();
        $previous = $this->periods->previousOf((string) $period['start_date']);
        $prevCharges = [];
        if ($previous !== null) {
            foreach ($this->alerts->apartmentsWithCharge($rule['scope_type'], $rule['scope_value'], (int) $previous['id']) as $c) {
                $prevCharges[(int) $c['apartment_id']] = $c;
            }
        }

        $out = [];
        foreach ($this->alerts->apartmentsWithCharge($rule['scope_type'], $rule['scope_value'], (int) $period['id']) as $charge) {
            $base = ['apartment_id' => (int) $charge['apartment_id'], 'apartment_code' => $charge['apartment_code'], 'meter_id' => null, 'meter_code' => null, 'dto' => null];
            $current = $this->validCost($charge, $period, $rule['utility_type']);
            if ($current === null) {
                $out[] = $base + ['result' => 'skip', 'reason' => 'Bản tính chưa xác nhận / CẦN TÍNH LẠI', 'actual' => null, 'compare' => null, 'percent' => null];
                continue;
            }
            $measure = $current;
            $compare = (string) $rule['threshold_value'];
            $percent = null;
            if ($rule['compare_base'] === 'ky_truoc') {
                $prev = isset($prevCharges[$base['apartment_id']]) ? $this->validCost($prevCharges[$base['apartment_id']], $previous, $rule['utility_type']) : null;
                if ($prev === null) {
                    $out[] = $base + ['result' => 'skip', 'reason' => 'Không có chi phí kỳ trước hợp lệ', 'actual' => $current, 'compare' => null, 'percent' => null];
                    continue;
                }
                $percent = ScopeConsumptionService::percent((int) Decimal::toCents($current) - (int) Decimal::toCents($prev), (int) Decimal::toCents($prev));
                if ($percent === null) {
                    $out[] = $base + ['result' => 'skip', 'reason' => '% so kỳ trước không xác định (kỳ trước = 0)', 'actual' => $current, 'compare' => $prev, 'percent' => null];
                    continue;
                }
                $measure = $percent;
                $compare = $prev;
            }
            $match = RuleConditionEvaluator::matches($rule['operator'], $measure, (string) $rule['threshold_value']);
            $out[] = $base + ['result' => $match ? 'match' : 'no_match', 'reason' => $match ? '' : 'Trong ngưỡng', 'actual' => $current, 'compare' => $compare, 'percent' => $percent];
        }

        return $out;
    }

    /** Tiền tiện ích (điện = TT + VAT; nước = TT) nếu bản tính dùng được; null nếu không. */
    private function validCost(array $charge, array $period, string $utility): ?string
    {
        if (!in_array($charge['status'], config('billing.lockable_statuses'), true)) {
            return null;
        }
        $state = $this->billing->consistency($period, $charge)['state'];
        if (!in_array($state, ['current', 'locked'], true)) {
            return null;
        }
        $cents = $utility === 'dien'
            ? MoneyCalculator::fromDb($charge['electricity_amount']) + MoneyCalculator::fromDb($charge['electricity_vat_amount'])
            : MoneyCalculator::fromDb($charge['water_amount']);

        return Decimal::fromCents($cents);
    }

    private function baselineStale(int $baselineId): bool
    {
        if (!array_key_exists($baselineId, $this->staleBaselines)) {
            $this->staleBaselines[$baselineId] = (bool) ($this->baselines->detail($baselineId)['source_changed'] ?? false);
        }

        return $this->staleBaselines[$baselineId];
    }

    /** @return ?int id cảnh báo mới; null nếu đã có (chống trùng BR-40) */
    private function createAlert(array $rule, array $period, array $c): ?int
    {
        return $this->transaction($this->alerts, function () use ($rule, $period, $c): ?int {
            if ($this->alerts->existsFor((int) $rule['id'], $c['apartment_id'], $c['meter_id'] ?? 0, (int) $period['id'])) {
                return null;
            }
            $percent = $c['percent'] !== null && bccomp(ltrim($c['percent'], '-'), '999999.99', 2) <= 0 ? $c['percent'] : null; // DECIMAL(8,2)
            $row = [
                'alert_rule_id' => (int) $rule['id'],
                'meter_id' => $c['meter_id'],
                'apartment_id' => $c['apartment_id'],
                'billing_period_id' => (int) $period['id'],
                'utility_type' => $rule['utility_type'],
                'source' => 'rule',
                'actual_value' => $c['actual'],
                'compare_value' => $c['compare'],
                'percent_change' => $percent,
                'priority' => $rule['priority_level'], // S7 [ASSUMPTION J-25]
                'status' => 'moi',
                'description' => self::explain($rule, $period, $c),
                'created_by' => null, // SYS-01 (rule engine)
            ];
            $id = $this->alerts->insertUnlessDuplicate($row);
            if ($id === null) {
                return null;
            }
            $this->alerts->insertHistory($id, null, 'moi', null, $rule['priority_level'], 'Sinh tự động theo quy tắc #' . $rule['id'], null);
            $this->audit('CREATE_ALERT_AUTO', 'alert', $id, null, $row + [
                'rule_snapshot' => array_intersect_key($rule, array_flip(['id', 'name', 'measure_type', 'utility_type', 'compare_base', 'operator', 'threshold_value', 'scope_type', 'scope_value', 'priority_level', 'updated_at'])),
                'comparison' => $c['dto'],
            ]);

            return $id;
        });
    }

    /** Câu giải thích lưu cùng cảnh báo (S8) - nêu dữ liệu và điều kiện, KHÔNG kết luận nguyên nhân (BR-29). */
    public static function explain(array $rule, array $period, array $c): string
    {
        $utility = config('alerts.utility_word')[$rule['utility_type']] ?? $rule['utility_type'];
        $measure = $rule['measure_type'] === 'chi_phi' ? 'Chi phí' : 'Tiêu thụ';
        $unit = $rule['measure_type'] === 'chi_phi' ? 'đ' : (config('pricing.utility_unit')[$rule['utility_type']] ?? '');
        $where = $c['meter_code'] !== null ? "công tơ {$c['meter_code']}" : "căn {$c['apartment_code']}";
        $value = format_decimal($c['actual']);
        if ($rule['compare_base'] === 'gia_tri_tuyet_doi') {
            $text = "{$measure} {$utility} kỳ {$period['period_code']} của {$where} = {$value} {$unit}";
        } else {
            $base = $rule['compare_base'] === 'ky_truoc' ? 'kỳ trước' : 'baseline';
            $text = "{$measure} {$utility} kỳ {$period['period_code']} của {$where} thay đổi " . pct_text($c['percent'])
                . " so với {$base} (" . format_decimal($c['compare']) . " → {$value} {$unit})";
        }
        $text .= ', thỏa điều kiện "' . AlertRuleService::describe($rule) . '" của quy tắc #' . $rule['id'] . ' «' . $rule['name'] . '». Cần xem xét — không phải kết luận sự cố.';

        return mb_substr($text, 0, 500);
    }
}
