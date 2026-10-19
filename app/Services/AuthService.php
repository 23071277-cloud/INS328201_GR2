<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLogger;
use App\Core\Session;
use App\Repositories\UserRepository;

/**
 * FN-01.01 Đăng nhập + BR-02 (khoá tạm sau 5 lần sai).
 * Toàn bộ validation nghiệp vụ nằm ở đây (Service), không ở Controller.
 */
class AuthService
{
    private UserRepository $userRepository;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
    }

    /** @return array{success: bool, message: ?string} */
    /** $password có #[\SensitiveParameter]: stack trace (app.log / trang lỗi debug) KHÔNG chứa mật khẩu (STEP 14). */
    public function attemptLogin(string $username, #[\SensitiveParameter] string $password): array
    {
        $user = $this->userRepository->findByUsername($username);

        if (!$user) {
            // VAL-01.02: không tiết lộ username có tồn tại hay không.
            return ['success' => false, 'message' => 'Tên đăng nhập hoặc mật khẩu không đúng.'];
        }

        $this->userRepository->unlockIfExpired((int) $user['id']);
        $user = $this->userRepository->find((int) $user['id']);

        if ($user['status'] === 'ngung') {
            // VAL-01.04
            return ['success' => false, 'message' => 'Tài khoản không còn hiệu lực.'];
        }

        if ($user['status'] === 'khoa_tam') {
            // VAL-01.03
            return [
                'success' => false,
                'message' => 'Tài khoản tạm khóa do nhập sai nhiều lần. Vui lòng thử lại sau hoặc liên hệ BQL.',
            ];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return $this->handleFailedPassword($user);
        }

        $this->handleSuccessfulLogin($user);

        return ['success' => true, 'message' => null];
    }

    private function handleFailedPassword(array $user): array
    {
        $this->userRepository->incrementFailedLogin((int) $user['id']);

        $maxAttempts = SystemParameterService::int('max_failed_login_attempts'); // [TL] BR-02 - FN-01.07
        $refreshed = $this->userRepository->find((int) $user['id']);

        if ((int) $refreshed['failed_login_count'] >= $maxAttempts) {
            $lockMinutes = SystemParameterService::int('account_lock_duration_minutes'); // [ASSUMPTION - J-03] - FN-01.07
            $lockedUntil = date('Y-m-d H:i:s', time() + $lockMinutes * 60);

            $this->userRepository->lockAccount((int) $user['id'], $lockedUntil);
            AuditLogger::log('account_locked', 'users', (string) $user['id'], null, ['reason' => 'exceeded_failed_login']);

            return [
                'success' => false,
                'message' => 'Tài khoản tạm khóa do nhập sai nhiều lần. Vui lòng thử lại sau hoặc liên hệ BQL.',
            ];
        }

        AuditLogger::log('login_failed', 'users', (string) $user['id']);

        return ['success' => false, 'message' => 'Tên đăng nhập hoặc mật khẩu không đúng.'];
    }

    private function handleSuccessfulLogin(array $user): void
    {
        $this->userRepository->resetFailedLogin((int) $user['id']);
        $this->userRepository->updateLastLogin((int) $user['id']);

        // Chống session fixation: đổi session ID ngay sau khi xác thực thành công.
        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        Session::put('username', $user['username']);
        Session::put('full_name', $user['full_name']);
        Session::put('user_role', $user['role']);
        Session::put('auth_fp', \App\Middleware\AuthMiddleware::fingerprint($user['password_hash'])); // V5 thu hồi phiên khi đổi mật khẩu

        AuditLogger::log('login_success', 'users', (string) $user['id']);
    }

    public function logout(): void
    {
        $userId = Session::get('user_id');
        if ($userId !== null) {
            AuditLogger::log('logout', 'users', (string) $userId);
        }
        Session::destroy();
    }
}
