<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\AuditLogger;
use App\Core\Flash;
use App\Core\Session;
use App\Repositories\UserRepository;

/**
 * [TL] FR-01: chặn truy cập khi chưa có phiên hợp lệ -> điều hướng /login.
 * STEP 15 (V5): đọc lại tài khoản từ DB ở MỌI request - tài khoản bị xoá / không còn 'hoat_dong'
 * (ngừng hoặc khoá tạm) / đã đổi mật khẩu (dấu vân tay sha1(password_hash) khác) -> huỷ phiên ngay
 * request kế tiếp, ghi audit session_revoked. Vai trò + họ tên trong phiên luôn lấy lại từ DB
 * (đổi vai trò có hiệu lực ngay, RBAC không dùng vai trò cũ).
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function handle(?string $param = null): bool
    {
        if (!Session::has('user_id')) {
            redirect('/login');
        }

        $userId = (int) Session::get('user_id');
        $user = (new UserRepository())->find($userId);
        $fingerprint = $user !== null ? self::fingerprint($user['password_hash']) : null;
        if (!Session::has('auth_fp') && $fingerprint !== null) {
            Session::put('auth_fp', $fingerprint); // phiên tạo trước STEP 15
        }

        $reason = match (true) {
            $user === null => 'user_missing',
            $user['status'] !== 'hoat_dong' => 'status_' . $user['status'],
            !hash_equals((string) Session::get('auth_fp'), (string) $fingerprint) => 'password_changed',
            default => null,
        };
        if ($reason !== null) {
            AuditLogger::log('session_revoked', 'users', (string) $userId, null, ['reason' => $reason]);
            Session::destroy();
            Flash::error('Phiên đăng nhập đã bị thu hồi (tài khoản bị khóa / ngừng hoặc đổi mật khẩu). Vui lòng đăng nhập lại.');
            redirect('/login');
        }

        Session::put('username', $user['username']);
        Session::put('full_name', $user['full_name']);
        Session::put('user_role', $user['role']);

        return true;
    }

    /** Dấu vân tay mật khẩu lưu trong phiên (không lưu hash thật). */
    public static function fingerprint(string $passwordHash): string
    {
        return sha1('auth_fp|' . $passwordHash);
    }
}
