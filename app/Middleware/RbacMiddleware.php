<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\AuditLogger;
use App\Core\Session;

/**
 * [TL] FR-02: RBAC — chặn thao tác ngoài quyền, trả 403 và ghi log.
 * $param = khoá quyền trong config/permissions.php, ví dụ "users.view".
 * Khai báo route: ['auth', 'rbac:users.view']
 */
class RbacMiddleware implements MiddlewareInterface
{
    public function handle(?string $param = null): bool
    {
        if ($param === null) {
            // Route không khai báo khoá quyền cụ thể -> chỉ cần đã đăng nhập (AuthMiddleware lo phần đó).
            return true;
        }

        $role = Session::get('user_role');
        $allowedRoles = config('permissions.' . $param, []);

        if (!in_array($role, $allowedRoles, true)) {
            AuditLogger::log('access_denied', 'route', $param, null, ['role' => $role]);
            http_response_code(403);
            require BASE_PATH . '/resources/views/errors/403.php';
            exit;
        }

        return true;
    }
}
