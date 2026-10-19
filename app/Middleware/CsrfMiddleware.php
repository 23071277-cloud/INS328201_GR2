<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Session;

/**
 * Bảo vệ CSRF cho mọi request POST (yêu cầu mục XVI).
 * Form nào gửi POST phải có input ẩn csrf_token (xem helper csrf_field()).
 */
class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(?string $param = null): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return true;
        }

        // csrf_token sai kiểu (mảng) -> '' -> không khớp -> 403, không 500 (STEP 8 phần A).
        $token = Request::postString('csrf_token');
        $sessionToken = (string) Session::get('csrf_token', '');

        if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            // 403 (không dùng 419): Apache 2.4 không biết mã 419 (mã riêng của
            // Laravel, ngoài chuẩn HTTP) và tự đổi thành 500. Trang hiển thị giữ nguyên.
            \App\Core\ErrorHandler::logEvent(sprintf(
                '[SECURITY] CSRF token rejected: %s %s ip=%s user_id=%s',
                $_SERVER['REQUEST_METHOD'] ?? '?',
                (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
                $_SERVER['REMOTE_ADDR'] ?? '-',
                (string) Session::get('user_id', '-')
            ));
            http_response_code(403);
            require BASE_PATH . '/resources/views/errors/419.php';
            exit;
        }

        return true;
    }
}
