<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Repositories\AlertRuleRepository;
use App\Repositories\ConsumptionAnalyticsRepository;

/**
 * FN-07.01 Cấu hình quy tắc ngưỡng - BR-25 (BQL), BR-04. Ngưỡng là DỮ LIỆU do BQL nhập (J-32), không có trong code.
 * Một ngưỡng duy nhất / rule (B10: chưa có ngưỡng kết hợp %+tuyệt đối). Rule không xoá - chỉ Tắt (S15).
 * Sửa rule không làm đổi cảnh báo cũ (snapshot - S8); rule mới áp từ lần đánh giá sau ([ĐX] mục 9).
 */
class AlertRuleService extends BaseService
{
    private AlertRuleRepository $rules;
    private ConsumptionAnalyticsRepository $analytics;

    public function __construct()
    {
        $this->rules = new AlertRuleRepository();
        $this->analytics = new ConsumptionAnalyticsRepository();
    }

    public function list(array $filters): array
    {
        return array_map(fn (array $r): array => $this->decorate($r), $this->rules->search($filters));
    }

    public function find(int $id): ?array
    {
        $row = $this->rules->find($id);

        return $row === null ? null : $this->decorate($row);
    }

    public function zones(): array
    {
        return $this->analytics->scopeEntities('zone', true);
    }

    public function create(array $input): int
    {
        $data = $this->validate($input);

        return $this->transaction($this->rules, function () use ($data): int {
            if ($this->rules->duplicateExists($data)) {
                throw ValidationException::withMessage('Đã có quy tắc đang bật với cấu hình giống hệt.', 'threshold_value');
            }
            $row = $data + ['status' => 'bat', 'created_by' => $this->actorId()];
            $id = $this->rules->insertRow($row);
            $this->audit('CREATE_ALERT_RULE', 'alert_rule', $id, null, $row);

            return $id;
        });
    }

    public function update(int $id, array $input): void
    {
        $data = $this->validate($input);

        $this->transaction($this->rules, function () use ($id, $data, $input): void {
            $current = $this->lockWithToken($id, (string) ($input['version_token'] ?? ''));
            if ($current['status'] === 'bat' && $this->rules->duplicateExists($data, $id)) {
                throw ValidationException::withMessage('Đã có quy tắc đang bật với cấu hình giống hệt.', 'threshold_value');
            }
            $diff = $this->diff($current, $data);
            if ($diff === []) {
                throw ValidationException::withMessage('Không có thay đổi.');
            }
            $this->rules->updateById($id, $data);
            $this->audit('UPDATE_ALERT_RULE', 'alert_rule', $id, array_intersect_key($current, $diff), $diff);
        });
    }

    /** Bật / Tắt (FN-07.01 mục 11). Rule Tắt không dùng cho lần đánh giá mới; cảnh báo cũ giữ liên kết. */
    public function setStatus(int $id, string $status, string $token): void
    {
        if (!array_key_exists($status, config('alerts.rule_status'))) {
            throw ValidationException::withMessage('Trạng thái quy tắc không hợp lệ.');
        }
        $this->transaction($this->rules, function () use ($id, $status, $token): void {
            $current = $this->lockWithToken($id, $token);
            if ($current['status'] === $status) {
                throw ValidationException::withMessage('Quy tắc đã ở trạng thái ' . config('alerts.rule_status')[$status] . '.');
            }
            if ($status === 'bat' && $this->rules->duplicateExists(array_intersect_key($current, array_flip(AlertRuleRepository::CONFIG_FIELDS)), $id)) {
                throw ValidationException::withMessage('Đã có quy tắc đang bật với cấu hình giống hệt — không bật được.');
            }
            $this->rules->updateById($id, ['status' => $status]);
            $this->audit($status === 'bat' ? 'ACTIVATE_ALERT_RULE' : 'DEACTIVATE_ALERT_RULE', 'alert_rule', $id, ['status' => $current['status']], ['status' => $status]);
        });
    }

    public static function versionToken(array $row): string
    {
        $parts = [$row['id'], $row['name'], $row['priority_level'], $row['status'], $row['updated_at']];
        foreach (AlertRuleRepository::CONFIG_FIELDS as $f) {
            $parts[] = (string) $row[$f];
        }

        return sha1(implode('|', $parts));
    }

    /** Câu mô tả điều kiện, vd "Tiêu thụ điện tăng > 30% so với baseline". */
    public static function describe(array $rule): string
    {
        $measure = mb_strtolower(config('alerts.measure_type')[$rule['measure_type']] ?? $rule['measure_type']);
        $utility = config('alerts.utility_word')[$rule['utility_type']] ?? $rule['utility_type'];
        $op = config('alerts.operator')[$rule['operator']] ?? $rule['operator'];
        $threshold = RuleConditionEvaluator::plain((string) $rule['threshold_value']);
        if ($rule['compare_base'] === 'gia_tri_tuyet_doi') {
            $unit = $rule['measure_type'] === 'chi_phi' ? 'đ' : (config('pricing.utility_unit')[$rule['utility_type']] ?? '');

            return ucfirst($measure) . " {$utility} {$op} {$threshold} {$unit}";
        }
        $base = $rule['compare_base'] === 'ky_truoc' ? 'kỳ trước' : 'baseline';

        return ucfirst($measure) . " {$utility} thay đổi {$op} {$threshold}% so với {$base}";
    }

    // ------------------------------------------------------------------

    private function lockWithToken(int $id, string $token): array
    {
        $current = $this->rules->findForUpdate($id);
        if ($current === null) {
            throw ValidationException::withMessage('Quy tắc không tồn tại.');
        }
        if ($token !== self::versionToken($current)) {
            throw ValidationException::withMessage('Quy tắc đã bị người khác thay đổi sau khi bạn mở form. Vui lòng tải lại trang.');
        }

        return $current;
    }

    private function validate(array $input): array
    {
        $errors = [];
        $s = static fn (string $k): string => trim((string) ($input[$k] ?? ''));
        $name = $s('name');
        $measure = $s('measure_type');
        $utility = $s('utility_type');
        $base = $s('compare_base');
        $operator = $s('operator');
        $threshold = $s('threshold_value');
        $scopeType = $s('scope_type');
        $scopeValue = $s('scope_value');
        $priority = $s('priority_level');

        if ($name === '') {
            $errors['name'][] = 'Vui lòng nhập tên quy tắc.';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'][] = 'Tên quy tắc tối đa 150 ký tự.';
        }
        if (!array_key_exists($measure, config('alerts.measure_type'))) {
            $errors['measure_type'][] = 'Đối tượng đo không hợp lệ.';
        }
        if (!array_key_exists($utility, config('master_data.utility_type'))) {
            $errors['utility_type'][] = 'Loại tiện ích không hợp lệ.';
        }
        if (!array_key_exists($base, config('alerts.compare_base'))) {
            $errors['compare_base'][] = 'Mốc so sánh không hợp lệ.';
        } elseif ($measure === 'chi_phi' && $base === 'baseline') {
            $errors['compare_base'][] = 'Chi phí chưa có baseline (chỉ có baseline cho tiêu thụ) — chọn kỳ trước hoặc giá trị tuyệt đối.';
        }
        if (!array_key_exists($operator, config('alerts.operator'))) {
            $errors['operator'][] = 'Toán tử không hợp lệ.';
        }
        if (!preg_match('/^\d{1,8}(\.\d{1,4})?$/', $threshold) || bccomp($threshold, '0', 4) <= 0) {
            $errors['threshold_value'][] = 'Ngưỡng phải là số > 0, tối đa 4 chữ số thập phân.';
        } elseif ($base !== 'gia_tri_tuyet_doi' && bccomp($threshold, (string) config('alerts.max_percent_threshold'), 4) > 0) {
            $errors['threshold_value'][] = 'Ngưỡng % tối đa ' . config('alerts.max_percent_threshold') . '%.';
        }
        $scopeStored = null;
        if (!array_key_exists($scopeType, config('alerts.rule_scope_type'))) {
            $errors['scope_type'][] = 'Phạm vi không hợp lệ.';
        } elseif ($scopeType === 'zone') {
            $zone = ctype_digit($scopeValue) ? $this->analytics->scopeEntity('zone', (int) $scopeValue) : null;
            if ($zone === null || $zone['status'] !== 'hoat_dong') {
                $errors['scope_value'][] = 'Vui lòng chọn khu vực đang hoạt động.';
            }
            $scopeStored = $scopeValue;
        } elseif ($scopeType === 'usage_type') {
            if ($measure === 'chi_phi') {
                $errors['scope_type'][] = 'Chi phí lưu theo căn hộ, không tách được theo loại sử dụng .';
            } elseif (!array_key_exists($scopeValue, config('master_data.usage_type'))) {
                $errors['scope_value'][] = 'Vui lòng chọn loại sử dụng.';
            }
            $scopeStored = $scopeValue;
        }
        if (!array_key_exists($priority, config('alerts.priority'))) {
            $errors['priority_level'][] = 'Mức ưu tiên không hợp lệ.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'name' => $name, 'measure_type' => $measure, 'utility_type' => $utility, 'compare_base' => $base, 'operator' => $operator,
            'threshold_value' => bcadd($threshold, '0', 4), 'scope_type' => $scopeType, 'scope_value' => $scopeStored, 'priority_level' => $priority,
        ];
    }

    private function decorate(array $row): array
    {
        $row['condition'] = self::describe($row);
        $row['version_token'] = self::versionToken($row);

        return $row;
    }
}
