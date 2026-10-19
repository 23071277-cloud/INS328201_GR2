<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\Decimal;
use App\Core\ValidationException;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\ConsumptionAnalyticsRepository;
use App\Repositories\ConsumptionTargetRepository;

/**
 * FN-06.03 Thiết lập mục tiêu / FN-06.04 Theo dõi mức đạt - FR-07, BR-25, BR-34, BR-11.
 * Mục tiêu "không vượt mức tiêu thụ": thực tế ≤ mục tiêu -> Đạt [SL]; > -> Không đạt; thiếu dữ liệu -> Chưa đủ dữ liệu.
 *   variance               = thực tế − mục tiêu
 *   achievement_percentage = thực tế ÷ mục tiêu × 100   (mục tiêu = 0 -> null, R11)
 *   tỷ lệ đạt (BR-34)      = số Đạt ÷ số được theo dõi (Đạt + Không đạt) × 100
 * Actual lấy ĐÚNG phạm vi của mục tiêu (mục tiêu khu so với tiêu thụ khu). Chỉ sửa giá trị mục tiêu (R13).
 */
class ConsumptionTargetService extends BaseService
{
    private ConsumptionTargetRepository $targets;
    private ConsumptionAnalyticsRepository $analytics;
    private BillingPeriodRepository $periods;
    private ScopeConsumptionService $consumption;
    private array $labels = [];

    public function __construct()
    {
        $this->targets = new ConsumptionTargetRepository();
        $this->analytics = new ConsumptionAnalyticsRepository();
        $this->periods = new BillingPeriodRepository();
        $this->consumption = new ScopeConsumptionService($this->analytics);
    }

    /** Danh sách mục tiêu kèm kết quả theo dõi. */
    public function list(array $filters): array
    {
        $periods = [];
        $rows = [];
        foreach ($this->targets->search($filters) as $row) {
            $period = $periods[$row['billing_period_id']] ??= $this->periods->find((int) $row['billing_period_id']);
            $rows[] = $this->decorate($row) + ['tracking' => $this->trackRow($row, $period)];
        }

        return $rows;
    }

    /** FN-06.04: kết quả theo dõi + tỷ lệ đạt (BR-34) theo loại phạm vi. */
    public function tracking(array $filters): array
    {
        $rows = $this->list($filters);
        $summary = [];
        foreach ($rows as $row) {
            $type = $row['scope_type'] . '|' . $row['utility_type'];
            $summary[$type] ??= ['scope_type' => $row['scope_type'], 'utility_type' => $row['utility_type'], 'dat' => 0, 'khong_dat' => 0, 'chua_du_du_lieu' => 0];
            $summary[$type][$row['tracking']['target_status']]++;
        }
        foreach ($summary as &$s) {
            $tracked = $s['dat'] + $s['khong_dat'];
            $s['tracked'] = $tracked;
            $s['attainment_rate'] = $tracked > 0 ? ScopeConsumptionService::percent($s['dat'], $tracked) : null;
        }
        unset($s);

        return ['rows' => $rows, 'summary' => array_values($summary)];
    }

    public function find(int $id): ?array
    {
        $row = $this->targets->findDetailed($id);

        return $row === null ? null : $this->decorate($row);
    }

    /** DTO theo dõi 1 mục tiêu (FN-07 / dashboard dùng lại - R14). */
    public function track(int $targetId): ?array
    {
        $row = $this->targets->find($targetId);

        return $row === null ? null : $this->trackRow($row, $this->periods->find((int) $row['billing_period_id']));
    }

    public function scopeOptions(): array
    {
        $out = [];
        foreach (config('analytics.target_scope_types') as $type) {
            $out[$type] = $this->analytics->scopeEntities($type, true);
        }

        return $out;
    }

    public function create(array $input): int
    {
        $data = $this->validate($input);

        return $this->transaction($this->targets, function () use ($data): int {
            $row = $data + ['created_by' => $this->actorId()];
            $id = $this->targets->insertUnlessDuplicate($row);
            if ($id === null) {
                throw ValidationException::withMessage('Đã có mục tiêu cho phạm vi và kỳ này.', 'billing_period_id'); // [ĐX] FN-06.03 VAL
            }
            $this->audit('CREATE_CONSUMPTION_TARGET', 'consumption_target', $id, null, $row);

            return $id;
        });
    }

    /** Chỉ sửa giá trị mục tiêu; version token chống ghi đè âm thầm. Kỳ đã khoá vẫn cho sửa ([ĐX] mục 9), có audit. */
    public function update(int $id, array $input): void
    {
        $value = $this->parseValue((string) ($input['target_value'] ?? ''));

        $this->transaction($this->targets, function () use ($id, $value, $input): void {
            $current = $this->targets->findForUpdate($id);
            if ($current === null) {
                throw ValidationException::withMessage('Mục tiêu không tồn tại.');
            }
            if ((string) ($input['version_token'] ?? '') !== self::versionToken($current)) {
                throw ValidationException::withMessage('Mục tiêu đã bị người khác thay đổi sau khi bạn mở form. Vui lòng tải lại trang.');
            }
            if ((int) Decimal::toCents((string) $current['target_value']) === $value) {
                throw ValidationException::withMessage('Không có thay đổi.', 'target_value');
            }
            $new = Decimal::fromCents($value);
            $this->targets->updateById($id, ['target_value' => $new]);
            $period = $this->periods->find((int) $current['billing_period_id']);
            $this->audit('UPDATE_CONSUMPTION_TARGET', 'consumption_target', $id,
                ['target_value' => $current['target_value']],
                ['target_value' => $new, 'period_status' => $period['status'] ?? null]);
        });
    }

    public static function versionToken(array $row): string
    {
        return sha1(implode('|', [$row['id'], $row['scope_type'], $row['scope_id'], $row['utility_type'], $row['billing_period_id'], $row['target_value'], $row['created_at']]));
    }

    // ------------------------------------------------------------------

    private function trackRow(array $row, ?array $period): array
    {
        $target = (int) Decimal::toCents((string) $row['target_value']);
        $m = $period !== null ? $this->consumption->measure($row['scope_type'], (int) $row['scope_id'], $row['utility_type'], $period)
            : ScopeConsumptionService::emptyResult();
        $actual = $m['value'];
        $status = $actual === null ? 'chua_du_du_lieu' : ($actual <= $target ? 'dat' : 'khong_dat');

        return [
            'target_id' => (int) $row['id'],
            'scope_type' => $row['scope_type'],
            'scope_id' => (int) $row['scope_id'],
            'utility_type' => $row['utility_type'],
            'period_id' => (int) $row['billing_period_id'],
            'target_value' => Decimal::fromCents($target),
            'actual_value' => ScopeConsumptionService::fmt($actual),
            'data_status' => $m['status'],
            'variance' => $actual === null ? null : Decimal::fromCents($actual - $target),
            'achievement_percentage' => $actual === null ? null : ScopeConsumptionService::percent($actual, $target),
            'target_status' => $status,
        ];
    }

    private function validate(array $input): array
    {
        $errors = [];
        $scopeType = (string) ($input['scope_type'] ?? '');
        $scopeIdRaw = (string) ($input['scope_id'] ?? '');
        $utility = (string) ($input['utility_type'] ?? '');
        $periodRaw = (string) ($input['billing_period_id'] ?? '');

        if (!in_array($scopeType, config('analytics.target_scope_types'), true)) {
            $errors['scope_id'][] = 'Phạm vi mục tiêu phải là căn hộ, khu vực hoặc tòa nhà.';
        } elseif (!ctype_digit($scopeIdRaw) || (int) $scopeIdRaw < 1) {
            $errors['scope_id'][] = 'Vui lòng chọn phạm vi.';
        } else {
            $entity = $this->analytics->scopeEntity($scopeType, (int) $scopeIdRaw);
            if ($entity === null) {
                $errors['scope_id'][] = 'Phạm vi không tồn tại (scope_type / scope_id không khớp).';
            } elseif ($entity['status'] !== 'hoat_dong') {
                $errors['scope_id'][] = 'Phạm vi đã ngừng hoạt động.';
            }
        }
        if (!array_key_exists($utility, config('master_data.utility_type'))) {
            $errors['utility_type'][] = 'Loại tiện ích không hợp lệ.';
        }
        if (!ctype_digit($periodRaw) || $this->periods->find((int) $periodRaw) === null) {
            $errors['billing_period_id'][] = 'Kỳ áp dụng không hợp lệ.';
        }
        $value = null;
        try {
            $value = $this->parseValue((string) ($input['target_value'] ?? ''));
        } catch (ValidationException $e) {
            $errors += $e->errors();
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['scope_type' => $scopeType, 'scope_id' => (int) $scopeIdRaw, 'utility_type' => $utility,
            'billing_period_id' => (int) $periodRaw, 'target_value' => Decimal::fromCents($value)];
    }

    private function parseValue(string $raw): int
    {
        $raw = trim($raw);
        if (Decimal::isNegative($raw)) {
            throw ValidationException::withMessage('Mục tiêu không được âm.', 'target_value');
        }
        $cents = Decimal::toCents($raw);
        if ($raw === '' || $cents === null) {
            throw ValidationException::withMessage('Mục tiêu phải là số, tối đa 2 chữ số thập phân.', 'target_value');
        }
        if ($cents === 0 && !config('analytics.target_allow_zero')) {
            throw ValidationException::withMessage('Mục tiêu phải lớn hơn 0.', 'target_value');
        }

        return $cents;
    }

    private function decorate(array $row): array
    {
        $labels = $this->labels[$row['scope_type']] ??= array_column($this->analytics->scopeEntities($row['scope_type']), 'label', 'id');
        $row['scope_label'] = $labels[$row['scope_id']] ?? ('#' . $row['scope_id']);
        $row['version_token'] = self::versionToken($row);

        return $row;
    }
}
