<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;

/**
 * FN-07 - bộ chuyển trạng thái cảnh báo theo G2 / ENUM schema (S1, [ASSUMPTION A-02 - J-04]).
 * Service gọi assert() trước MỌI thay đổi trạng thái -> không phụ thuộc việc ẩn nút trên giao diện.
 */
final class AlertStateMachine
{
    /** hành động => [trạng thái được phép => trạng thái sau] */
    public const TRANSITIONS = [
        'review' => ['moi' => 'da_review'],                       // FN-07.06
        'request_verification' => ['da_review' => 'can_kiem_tra_lai'], // FN-07.07
        'confirm' => ['can_kiem_tra_lai' => 'da_xac_nhan'],        // FN-07.09
        'close' => ['da_review' => 'da_dong', 'da_xac_nhan' => 'da_dong'], // FN-07.10
        'reopen' => ['da_dong' => 'da_review'],                    // FN-07.10
        // [BA Proposal J-16] kết luận A/B/C của BQL (có / không qua Kỹ thuật); đổi kết luận khi đã xác nhận
        'conclude' => ['da_review' => 'da_xac_nhan', 'can_kiem_tra_lai' => 'da_xac_nhan', 'da_xac_nhan' => 'da_xac_nhan'],
    ];

    /** Đổi mức ưu tiên: mọi trạng thái chưa đóng (VAL-07.01, BR-30). */
    public const PRIORITY_EDITABLE = ['moi', 'da_review', 'can_kiem_tra_lai', 'da_xac_nhan'];

    public static function can(string $action, string $from): bool
    {
        return isset(self::TRANSITIONS[$action][$from]);
    }

    /** @return string trạng thái sau */
    public static function assert(string $action, string $from): string
    {
        if (!self::can($action, $from)) {
            if ($from === 'da_dong' && $action !== 'reopen') {
                throw ValidationException::withMessage('Cảnh báo đã đóng. Vui lòng mở lại trước khi thay đổi.'); // VAL-07.01
            }
            $label = config('alerts.status')[$from] ?? $from;
            throw ValidationException::withMessage("Không thể thực hiện thao tác này khi cảnh báo đang ở trạng thái \"{$label}\" (có thể đã được người khác xử lý — vui lòng tải lại).");
        }

        return self::TRANSITIONS[$action][$from];
    }
}
