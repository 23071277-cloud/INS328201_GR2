<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Decimal;

/**
 * FN-04.04 Kiểm tra hợp lệ chỉ số - MỘT bộ quy tắc dùng chung cho nhập tay (04.02),
 * import (04.03) và sửa (04.07). [TL] FR-04, 2.4, BR-07, BR-08, BR-10.
 *
 * validate() trả về danh sách lỗi theo thứ tự ưu tiên; nhập tay hiển thị lỗi đầu tiên,
 * import gom mọi lỗi ([ĐX] FN-04.04 mục 6). Lỗi CSC < CSĐ có code 'VAL-04.02' và
 * meter_reset = true để giao diện chỉ ra luồng "Thay/reset đồng hồ" (FN-04.06 - BLOCKED B5/J-11);
 * tuyệt đối không cho lưu CSC < CSĐ như chỉ số thường.
 */
final class MeterReadingValidator
{
    /**
     * @param array{
     *   period: array, meter: array, current_raw: ?string,
     *   previous_cents: ?int, previous_blocked: ?string, duplicate: bool
     * } $ctx  meter phải có status + apartment_status
     * @return array{errors: array<int, array{code:string,field:string,message:string,meter_reset:bool}>, consumption_cents: ?int, current_cents: ?int}
     */
    public function validate(array $ctx): array
    {
        $errors = [];
        $add = static function (string $code, string $field, string $message, bool $reset = false) use (&$errors): void {
            $errors[] = ['code' => $code, 'field' => $field, 'message' => $message, 'meter_reset' => $reset];
        };

        if (($ctx['period']['status'] ?? '') === 'da_khoa') {
            $add('VAL-04.04', '_', 'Kỳ đã khóa, không thể thay đổi chỉ số.');
        }
        if (($ctx['meter']['status'] ?? '') !== 'hoat_dong' || ($ctx['meter']['apartment_status'] ?? '') !== 'hoat_dong') {
            $add('VAL-04.05', '_', 'Công tơ không còn hoạt động.');
        }
        if ($ctx['duplicate']) {
            $add('VAL-04.03', '_', 'Đã có chỉ số của công tơ này trong kỳ.');
        }
        if ($ctx['previous_blocked'] !== null) {
            $add('B7', '_', $ctx['previous_blocked']);
        }

        $raw = $ctx['current_raw'];
        $current = null;
        if ($raw === null || trim($raw) === '') {
            $add('VAL-04.01', 'current_reading', 'Chỉ số phải là số và không được để trống.');
        } elseif (Decimal::isNegative($raw)) {
            $add('VAL-04.06', 'current_reading', 'Chỉ số không được âm.');
        } else {
            $current = Decimal::toCents($raw);
            if ($current === null) {
                $add('VAL-04.01', 'current_reading', 'Chỉ số phải là số và không được để trống (tối đa 2 chữ số thập phân).');
            }
        }

        $consumption = null;
        if ($current !== null && $ctx['previous_cents'] !== null) {
            if ($current < $ctx['previous_cents']) {
                // VAL-04.02 / BR-07
                $add('VAL-04.02', 'current_reading', 'Chỉ số cuối nhỏ hơn chỉ số đầu. Vui lòng nhập lại hoặc xác nhận thay công tơ.', true);
            } else {
                $consumption = $current - $ctx['previous_cents']; // BR-12: CL = CSC − CSĐ (server tính)
            }
        }

        return ['errors' => $errors, 'consumption_cents' => $errors === [] ? $consumption : null, 'current_cents' => $current];
    }
}
