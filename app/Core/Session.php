<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Wrapper cho $_SESSION.
 * [TL] NFR-01: tự đăng xuất sau N phút không hoạt động (mặc định 30 - Proposed).
 * [TL] BR-03: session dùng cho đăng nhập/khóa tạm.
 */
class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => self::isHttps(),
            ]);
            session_start();
        }

        self::enforceTimeout();

        if (!self::has('csrf_token')) {
            self::put('csrf_token', bin2hex(random_bytes(32)));
        }
    }

    private static function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    /**
     * [TL - NFR-01] Hết hạn phiên sau X phút không hoạt động -> xoá dữ liệu
     * phiên cũ (không điều hướng tại đây, AuthMiddleware sẽ thấy user_id mất
     * và tự chuyển về /login).
     */
    private static function enforceTimeout(): void
    {
        $timeoutSeconds = \App\Services\SystemParameterService::int('session_timeout_minutes') * 60; // FN-01.07 (DB, fallback .env)
        $lastActivity = self::get('last_activity');

        if ($lastActivity !== null && (time() - (int) $lastActivity) > $timeoutSeconds) {
            $_SESSION = [];
            session_regenerate_id(true);
        }

        self::put('last_activity', time());
    }

    public static function put(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Chống session fixation: gọi ngay sau khi đăng nhập thành công. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Dùng khi đăng xuất chủ động (FN-01.02). */
    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        session_start();
        session_regenerate_id(true);
    }
}
