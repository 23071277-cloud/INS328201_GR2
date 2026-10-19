<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Lang;
use App\Core\Request;

/**
 * Đổi ngôn ngữ giao diện (vi / en) - lưu trong session, quay lại đúng trang đang xem.
 * Chỉ là tuỳ chọn hiển thị của người dùng (như đăng xuất): auth + csrf, không gắn quyền nghiệp vụ, không ghi DB.
 */
class LocaleController extends BaseController
{
    public function switch(): void
    {
        Lang::setLocale(Request::postString('locale')); // giá trị lạ bị bỏ qua (giữ ngôn ngữ hiện tại)
        $this->redirect(self::safeBack(Request::postRawString('back')));
    }

    /** Chỉ chấp nhận đường dẫn nội bộ của ứng dụng ("/x?y") - chặn open redirect (//host, http://, \, xuống dòng). */
    public static function safeBack(string $back): string
    {
        if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//') || preg_match('#[\\\\\r\n\0]|^/[^/?]*:#', $back) || strlen($back) > 2000) {
            return '/dashboard';
        }

        return $back;
    }
}
