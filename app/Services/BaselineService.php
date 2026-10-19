<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\Decimal;
use App\Core\ValidationException;
use App\Repositories\BaselineRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\ConsumptionAnalyticsRepository;

/**
 * FN-06.01 Cấu hình baseline - BR-25 (BQL duyệt), BR-26, BR-11. Mỗi dòng `baselines` là một PHIÊN BẢN cố định
 * (R1): tính một lần khi lưu từ kỳ ĐÃ KHÓA trước ngày hiệu lực (R2-R5), lưu `computed_value` / `manual_value`.
 * Không có phiên bản mới / sửa / ngừng hiệu lực trong kỳ đã khóa (R6) -> diễn giải kỳ khóa không đổi.
 * Trùng (phạm vi, tiện ích, ngày hiệu lực): khoá dòng thực thể phạm vi rồi kiểm tra (R7, schema không có UNIQUE).
 */
class BaselineService extends BaseService
{
    private BaselineRepository $baselines;
    private ConsumptionAnalyticsRepository $analytics;
    private BillingPeriodRepository $periods;
    private ScopeConsumptionService $consumption;
    private array $labels = [];

    public function __construct()
    {
        $this->baselines = new BaselineRepository();
        $this->analytics = new ConsumptionAnalyticsRepository();
        $this->periods = new BillingPeriodRepository();
        $this->consumption = new ScopeConsumptionService($this->analytics);
    }

    public function list(array $filters): array
    {
        return array_map(fn (array $row): array => $this->decorate($row), $this->baselines->search($filters, date('Y-m-d')));
    }

    public function find(int $id): ?array
    {
        $row = $this->baselines->findDetailed($id, date('Y-m-d'));

        return $row === null ? null : $this->decorate($row);
    }

    /** Chi tiết + tính lại từ dữ liệu hiện tại để phát hiện nguồn đã đổi sau khi lập (R9, chỉ báo - không tự sửa). */
    public function detail(int $id): ?array
    {
        $row = $this->find($id);
        if ($row === null) {
            return null;
        }
        $row['recheck'] = $row['method'] === 'nhap_tay' ? null
            : $this->compute($row['scope_type'], (int) $row['scope_id'], $row['utility_type'], $row['method'], (int) ($row['n_periods'] ?? 0), null, $row['effective_date']);
        $row['source_changed'] = $row['recheck'] !== null && ($row['recheck']['status'] !== 'ok' || $row['recheck']['value'] !== $row['value_cents']);

        return $row;
    }

    /** Phiên bản hiệu lực của phạm vi tại ngày (R1) - kèm 'value_cents'. */
    public function effective(string $scopeType, int $scopeId, string $utility, string $date): ?array
    {
        $row = $this->baselines->effectiveFor($scopeType, $scopeId, $utility, $date);

        return $row === null ? null : $this->withValue($row);
    }

    /** @return array<int,array> scope_id => phiên bản hiệu lực (kèm value_cents) */
    public function effectiveMap(string $scopeType, string $utility, string $date): array
    {
        return array_map(fn (array $row): array => $this->withValue($row), $this->baselines->effectiveMap($scopeType, $utility, $date));
    }

    public function scopeOptions(): array
    {
        $out = [];
        foreach (array_keys(config('analytics.scope_type')) as $type) {
            $out[$type] = $this->analytics->scopeEntities($type, true);
        }

        return $out;
    }

    /** Tính thử (FN-06.01 "tính thử baseline … hiển thị kết quả mẫu") - không ghi gì. */
    public function preview(array $input): array
    {
        $data = $this->validate($input, null);

        return $this->computeFor($data);
    }

    public function create(array $input): int
    {
        $data = $this->validate($input, null);

        return $this->transaction($this->baselines, function () use ($data): int {
            $this->lockScope($data);
            if ($this->baselines->duplicateExists($data['scope_type'], $data['scope_id'], $data['utility_type'], $data['effective_date'])) {
                throw ValidationException::withMessage('Đã có baseline hiệu lực cho phạm vi, tiện ích và ngày hiệu lực này.', 'effective_date');
            }
            $result = $this->requireComputed($data);
            $row = $this->rowFrom($data, $result) + ['status' => 'hieu_luc', 'created_by' => $this->actorId()];
            $id = $this->baselines->insertRow($row);
            $this->audit('CREATE_BASELINE', 'baseline', $id, null, $row + ['source_periods' => $result['periods_used']]);

            return $id;
        });
    }

    public function update(int $id, array $input): void
    {
        $plain = $this->baselines->find($id);
        if ($plain === null) {
            throw ValidationException::withMessage('Baseline không tồn tại.');
        }
        // phạm vi không đổi khi sửa (đổi phạm vi = tạo baseline mới)
        $input['scope_type'] = $plain['scope_type'];
        $input['scope_id'] = (string) $plain['scope_id'];
        $input['utility_type'] = $plain['utility_type'];
        $data = $this->validate($input, $id);

        $this->transaction($this->baselines, function () use ($id, $data, $input): void {
            $this->lockScope($data);
            $current = $this->baselines->findForUpdate($id);
            $this->assertToken($current, (string) ($input['version_token'] ?? ''));
            $this->assertEditable($current);
            if ($this->baselines->duplicateExists($data['scope_type'], $data['scope_id'], $data['utility_type'], $data['effective_date'], $id)) {
                throw ValidationException::withMessage('Đã có baseline hiệu lực cho phạm vi, tiện ích và ngày hiệu lực này.', 'effective_date');
            }
            $result = $this->requireComputed($data);
            $changes = $this->rowFrom($data, $result);
            $diff = $this->diff($current, $changes);
            if ($diff === []) {
                throw ValidationException::withMessage('Không có thay đổi.');
            }
            $this->baselines->updateById($id, $changes);
            $this->audit('UPDATE_BASELINE', 'baseline', $id, array_intersect_key($current, $diff), $diff + ['source_periods' => $result['periods_used']]);
        });
    }

    /** Ngừng (Đang hiệu lực -> Hết hiệu lực, FN-06.01 mục 11) - chỉ phiên bản chưa dùng cho kỳ đã khóa (R6). */
    public function deactivate(int $id, string $token): void
    {
        $plain = $this->baselines->find($id);
        if ($plain === null) {
            throw ValidationException::withMessage('Baseline không tồn tại.');
        }
        $this->transaction($this->baselines, function () use ($id, $plain, $token): void {
            $this->analytics->scopeEntity($plain['scope_type'], (int) $plain['scope_id'], true);
            $current = $this->baselines->findForUpdate($id);
            $this->assertToken($current, $token);
            if ($current['status'] === 'het_hieu_luc') {
                throw ValidationException::withMessage('Baseline đã ở trạng thái Hết hiệu lực.');
            }
            $this->assertEditable($current);
            $this->baselines->updateById($id, ['status' => 'het_hieu_luc']);
            $this->audit('DEACTIVATE_BASELINE', 'baseline', $id, ['status' => 'hieu_luc'], ['status' => 'het_hieu_luc']);
        });
    }

    /** null = sửa / ngừng được; ngược lại là lý do (R6). */
    public function editBlockReason(array $row): ?string
    {
        if ($row['status'] !== 'hieu_luc') {
            return 'Baseline đã Hết hiệu lực.';
        }
        $locked = $this->periods->lastLockedEndDate();
        if ($locked !== null && $row['effective_date'] <= $locked) {
            return 'Phiên bản đã được dùng cho kỳ đã khóa (kết thúc ' . format_date($locked) . ') — chỉ thay bằng phiên bản mới có ngày hiệu lực sau đó.';
        }

        return null;
    }

    public static function versionToken(array $row): string
    {
        return sha1(implode('|', [$row['id'], $row['method'], $row['n_periods'] ?? '', $row['manual_value'] ?? '', $row['computed_value'] ?? '', $row['effective_date'], $row['status']]));
    }

    // ------------------------------------------------------------------
    // Tính baseline (R2-R5)
    // ------------------------------------------------------------------

    /**
     * @return array{status:string, value:?int, periods_used:array, skipped:array, warnings:string[], message:?string}
     *   status: ok | insufficient_history
     */
    public function compute(string $scopeType, int $scopeId, string $utility, string $method, int $n, ?int $manualCents, string $effectiveDate): array
    {
        $result = ['status' => 'ok', 'value' => null, 'periods_used' => [], 'skipped' => [], 'warnings' => [], 'message' => null];
        if ($method === 'nhap_tay') {
            $result['value'] = $manualCents;

            return $result;
        }

        $candidates = $this->analytics->periodsEndingBefore($effectiveDate);
        if ($method === 'ky_truoc') {
            $candidates = array_slice($candidates, 0, 1); // R3: chỉ kỳ liền trước, không lùi tiếp
        } elseif ($method === 'cung_ky_nam_truoc') {
            $code = date('m', strtotime($effectiveDate)) . '/' . ((int) date('Y', strtotime($effectiveDate)) - 1);
            $period = $this->analytics->periodByCode($code);
            $candidates = $period !== null && $period['end_date'] < $effectiveDate ? [$period] : [];
            if ($candidates === []) {
                $result['skipped'][] = ['period_code' => $code, 'reason' => 'không có kỳ'];
            }
        }
        $need = $method === 'trung_binh_n_ky' ? $n : 1;

        foreach ($candidates as $period) {
            if (count($result['periods_used']) >= $need) {
                break;
            }
            $reason = null;
            if ($period['status'] !== 'da_khoa') {
                $reason = 'kỳ chưa khóa (' . (config('readings.period_status')[$period['status']] ?? $period['status']) . ')';
            } else {
                $m = $this->consumption->measure($scopeType, $scopeId, $utility, $period);
                // A locked flag alone does not prove completeness (e.g. manually locked periods).
                // Keep this stricter eligibility rule local to building averages.
                $buildingAverage = $scopeType === 'building' && $method === 'trung_binh_n_ky';
                $missing = $buildingAverage
                    ? ($this->analytics->missingByScope($scopeType, $utility, (int) $period['id'], $scopeId)[$scopeId] ?? 0)
                    : 0;
                if ($buildingAverage && ($missing > 0 || $m['drafts'] > 0)) {
                    $reason = "chỉ số chưa đầy đủ: {$m['confirmed']} công tơ đã xác nhận, {$m['drafts']} bản nháp, {$missing} công tơ thiếu chỉ số";
                } elseif ($m['status'] !== 'ok') {
                    $reason = config('analytics.data_status')[$m['status']];
                } else {
                    $result['periods_used'][] = ['id' => (int) $period['id'], 'period_code' => $period['period_code'], 'value' => Decimal::fromCents($m['value']),
                        'reason' => $buildingAverage ? "kỳ đã khóa, đầy đủ {$m['confirmed']} chỉ số đã xác nhận" : 'kỳ đã khóa, dữ liệu hợp lệ'];
                    continue;
                }
            }
            $result['skipped'][] = ['period_code' => $period['period_code'], 'reason' => $reason];
        }

        $found = count($result['periods_used']);
        if ($found < $need) {
            $result['status'] = 'insufficient_history';
            $result['message'] = "INSUFFICIENT_HISTORY: cần {$need} kỳ đã khóa có dữ liệu trước ngày hiệu lực, chỉ có {$found} — chưa đủ dữ liệu lịch sử, không lập baseline.";

            return $result;
        }

        $sum = 0;
        foreach ($result['periods_used'] as $p) {
            $sum += (int) Decimal::toCents($p['value']);
        }
        $result['value'] = intdiv(2 * $sum + $found, 2 * $found); // trung bình, 2 chữ số (xu), half-up - CL không âm
        if ($method === 'trung_binh_n_ky' && $n < (int) config('analytics.min_reliable_periods')) {
            $result['warnings'][] = 'Baseline dựa trên ít hơn ' . config('analytics.min_reliable_periods') . ' kỳ có độ tin cậy thấp.';
        }

        return $result;
    }

    // ------------------------------------------------------------------

    private function computeFor(array $data): array
    {
        return $this->compute($data['scope_type'], $data['scope_id'], $data['utility_type'], $data['method'], $data['n_periods'] ?? 0, $data['manual_cents'], $data['effective_date']);
    }

    private function requireComputed(array $data): array
    {
        $result = $this->computeFor($data);
        if ($result['status'] !== 'ok') {
            throw ValidationException::withMessage((string) $result['message'], 'method');
        }

        return $result;
    }

    private function rowFrom(array $data, array $result): array
    {
        return [
            'scope_type' => $data['scope_type'],
            'scope_id' => $data['scope_id'],
            'utility_type' => $data['utility_type'],
            'method' => $data['method'],
            'n_periods' => $data['method'] === 'trung_binh_n_ky' ? $data['n_periods'] : null,
            'manual_value' => $data['method'] === 'nhap_tay' ? Decimal::fromCents($data['manual_cents']) : null,
            'computed_value' => $data['method'] === 'nhap_tay' ? null : Decimal::fromCents($result['value']),
            'effective_date' => $data['effective_date'],
        ];
    }

    private function lockScope(array $data): void
    {
        $entity = $this->analytics->scopeEntity($data['scope_type'], $data['scope_id'], true);
        if ($entity === null) {
            throw ValidationException::withMessage('Phạm vi không tồn tại.', 'scope_id');
        }
    }

    private function assertToken(?array $current, string $token): void
    {
        if ($current === null || $token !== self::versionToken($current)) {
            throw ValidationException::withMessage('Baseline đã bị người khác thay đổi sau khi bạn mở form. Vui lòng tải lại trang.');
        }
    }

    private function assertEditable(array $row): void
    {
        $reason = $this->editBlockReason($row);
        if ($reason !== null) {
            throw ValidationException::withMessage($reason);
        }
    }

    /** Server-side validation (VAL FN-06.01 + R6, R8). */
    private function validate(array $input, ?int $exceptId): array
    {
        $errors = [];
        $scopeType = (string) ($input['scope_type'] ?? '');
        $scopeIdRaw = (string) ($input['scope_id'] ?? '');
        $utility = (string) ($input['utility_type'] ?? '');
        $method = (string) ($input['method'] ?? '');
        $nRaw = trim((string) ($input['n_periods'] ?? ''));
        $manualRaw = trim((string) ($input['manual_value'] ?? ''));
        $date = trim((string) ($input['effective_date'] ?? ''));

        if (!array_key_exists($scopeType, config('analytics.scope_type'))) {
            $errors['scope_id'][] = 'Phạm vi không hợp lệ.';
        } elseif (!ctype_digit($scopeIdRaw) || (int) $scopeIdRaw < 1) {
            $errors['scope_id'][] = 'Vui lòng chọn phạm vi.';
        } else {
            $entity = $this->analytics->scopeEntity($scopeType, (int) $scopeIdRaw);
            if ($entity === null) {
                $errors['scope_id'][] = 'Phạm vi không tồn tại (scope_type / scope_id không khớp).';
            } elseif ($exceptId === null && $entity['status'] !== 'hoat_dong') {
                $errors['scope_id'][] = 'Phạm vi đã ngừng hoạt động.';
            } elseif ($scopeType === 'meter' && $utility !== '' && $entity['utility_type'] !== $utility) {
                $errors['utility_type'][] = 'Loại tiện ích không khớp với công tơ.';
            }
        }
        if (!array_key_exists($utility, config('master_data.utility_type'))) {
            $errors['utility_type'][] = 'Loại tiện ích không hợp lệ.';
        }
        if (!array_key_exists($method, config('analytics.baseline_method'))) {
            $errors['method'][] = 'Phương pháp không hợp lệ.';
        }
        $n = null;
        if ($method === 'trung_binh_n_ky') {
            $max = (int) config('analytics.max_n_periods');
            if ($nRaw === '' || !ctype_digit($nRaw) || (int) $nRaw < 1 || (int) $nRaw > $max) {
                $errors['n_periods'][] = "Số kỳ N phải là số nguyên từ 1 đến {$max}.";
            } else {
                $n = (int) $nRaw;
            }
        }
        $manual = null;
        if ($method === 'nhap_tay') {
            if (Decimal::isNegative($manualRaw)) {
                $errors['manual_value'][] = 'Giá trị baseline không được âm.';
            } elseif (($manual = Decimal::toCents($manualRaw)) === null) {
                $errors['manual_value'][] = 'Giá trị baseline phải là số ≥ 0, tối đa 2 chữ số thập phân.';
            }
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            $errors['effective_date'][] = 'Ngày hiệu lực không hợp lệ.';
        } else {
            $locked = $this->periods->lastLockedEndDate();
            if ($locked !== null && $date <= $locked) {
                $errors['effective_date'][] = 'Ngày hiệu lực phải sau ngày kết thúc kỳ đã khóa gần nhất (' . format_date($locked) . ') — không áp hồi tố cho kỳ đã khóa.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['scope_type' => $scopeType, 'scope_id' => (int) $scopeIdRaw, 'utility_type' => $utility, 'method' => $method,
            'n_periods' => $n, 'manual_cents' => $manual, 'effective_date' => $date];
    }

    private function withValue(array $row): array
    {
        $raw = $row['method'] === 'nhap_tay' ? $row['manual_value'] : $row['computed_value'];
        $row['value_cents'] = $raw === null ? null : Decimal::toCents((string) $raw);

        return $row;
    }

    private function decorate(array $row): array
    {
        $row = $this->withValue($row);
        $labels = $this->labels[$row['scope_type']] ??= array_column($this->analytics->scopeEntities($row['scope_type']), 'label', 'id');
        $row['scope_label'] = $labels[$row['scope_id']] ?? ('#' . $row['scope_id']);
        $row['version_token'] = self::versionToken($row);

        return $row;
    }
}
