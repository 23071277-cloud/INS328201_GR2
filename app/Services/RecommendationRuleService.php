<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Repositories\RecommendationRuleRepository;

/**
 * FN-08.01 Quản lý quy tắc khuyến nghị - BR-33, BR-25. "Rule đã duyệt" = rule Bật do BQL lưu [ASSUMPTION T1/J-26]
 * (không có luồng duyệt riêng). Rule không xoá, chỉ Tắt (T8). Version token = hash nội dung (bảng không có updated_at).
 */
class RecommendationRuleService extends BaseService
{
    private RecommendationRuleRepository $rules;

    public function __construct()
    {
        $this->rules = new RecommendationRuleRepository();
    }

    public function list(array $filters): array
    {
        return array_map(fn (array $r): array => $this->decorate($r), $this->rules->search($filters));
    }

    public function find(int $id): ?array
    {
        $row = $this->rules->findWithUsage($id);

        return $row === null ? null : $this->decorate($row);
    }

    public function create(array $input): int
    {
        $data = $this->validate($input);

        return $this->transaction($this->rules, function () use ($data): int {
            $row = $data + ['status' => 'bat', 'created_by' => $this->actorId()];
            $id = $this->rules->insertRow($row);
            $this->audit('CREATE_RECOMMENDATION_RULE', 'recommendation_rule', $id, null, $row);

            return $id;
        });
    }

    /** Sửa rule: khuyến nghị đã sinh giữ snapshot nội dung (T5). */
    public function update(int $id, array $input): void
    {
        $data = $this->validate($input);

        $this->transaction($this->rules, function () use ($id, $data, $input): void {
            $current = $this->lock($id, (string) ($input['version_token'] ?? ''));
            $diff = $this->diff($current, $data);
            if ($diff === []) {
                throw ValidationException::withMessage('Không có thay đổi.');
            }
            $this->rules->updateById($id, $data);
            $this->audit('UPDATE_RECOMMENDATION_RULE', 'recommendation_rule', $id, array_intersect_key($current, $diff), $diff);
        });
    }

    public function setStatus(int $id, string $status, string $token): void
    {
        if (!array_key_exists($status, config('recommendations.rule_status'))) {
            throw ValidationException::withMessage('Trạng thái quy tắc không hợp lệ.');
        }
        $this->transaction($this->rules, function () use ($id, $status, $token): void {
            $current = $this->lock($id, $token);
            if ($current['status'] === $status) {
                throw ValidationException::withMessage('Quy tắc đã ở trạng thái ' . config('recommendations.rule_status')[$status] . '.');
            }
            $this->rules->updateById($id, ['status' => $status]);
            $this->audit($status === 'bat' ? 'ACTIVATE_RECOMMENDATION_RULE' : 'DEACTIVATE_RECOMMENDATION_RULE', 'recommendation_rule', $id,
                ['status' => $current['status']], ['status' => $status]);
        });
    }

    public static function versionToken(array $row): string
    {
        return sha1(implode('|', [$row['id'], $row['name'], $row['condition_type'], (string) $row['condition_params'], $row['recommendation_text'], $row['status']]));
    }

    /** Mô tả điều kiện cho giao diện, vd "CL = 0 trong 2 kỳ liên tiếp (nước)". */
    public static function describe(array $rule): string
    {
        $params = self::params($rule);
        $text = config('recommendations.condition_type')[$rule['condition_type']] ?? $rule['condition_type'];
        if (isset($params['n_periods'])) {
            $text = str_replace('N', (string) $params['n_periods'], $text);
        }
        if (isset($params['utility_type'])) {
            $text .= ' — chỉ ' . (config('alerts.utility_word')[$params['utility_type']] ?? $params['utility_type']);
        }

        return $text;
    }

    public static function params(array $rule): array
    {
        $decoded = json_decode((string) ($rule['condition_params'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    // ------------------------------------------------------------------

    private function lock(int $id, string $token): array
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
        $name = trim((string) ($input['name'] ?? ''));
        $type = (string) ($input['condition_type'] ?? '');
        $n = trim((string) ($input['n_periods'] ?? ''));
        $utility = (string) ($input['utility_type'] ?? '');
        $text = trim((string) ($input['recommendation_text'] ?? ''));

        if ($name === '') {
            $errors['name'][] = 'Vui lòng nhập tên quy tắc.';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'][] = 'Tên quy tắc tối đa 150 ký tự.';
        }
        if (!array_key_exists($type, config('recommendations.condition_type'))) {
            $errors['condition_type'][] = 'Vui lòng chọn điều kiện.'; // [ĐX] điều kiện bắt buộc
        }
        $params = [];
        if (in_array($type, config('recommendations.needs_n_periods'), true)) {
            $max = (int) config('recommendations.max_n_periods');
            if (!ctype_digit($n) || (int) $n < 1 || (int) $n > $max) {
                $errors['n_periods'][] = "Số kỳ N phải là số nguyên từ 1 đến {$max}.";
            } else {
                $params['n_periods'] = (int) $n;
            }
        }
        if ($utility !== '') {
            if (!array_key_exists($utility, config('master_data.utility_type'))) {
                $errors['utility_type'][] = 'Loại tiện ích không hợp lệ.';
            } else {
                $params['utility_type'] = $utility;
            }
        }
        if ($text === '') {
            $errors['recommendation_text'][] = 'Vui lòng nhập nội dung khuyến nghị.'; // [ĐX]
        } elseif (mb_strlen($text) > 500) {
            $errors['recommendation_text'][] = 'Nội dung tối đa 500 ký tự.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['name' => $name, 'condition_type' => $type, 'condition_params' => $params === [] ? null : json_encode($params), 'recommendation_text' => $text];
    }

    private function decorate(array $row): array
    {
        $row['condition'] = self::describe($row);
        $row['params'] = self::params($row);
        $row['version_token'] = self::versionToken($row);

        return $row;
    }
}
