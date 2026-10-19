<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\AuditLogRepository;

/**
 * [TL] NFR-05, BR-04: ghi vết ai/khi nào/đối tượng/giá trị cũ-mới cho mọi thao
 * tác thuộc diện audit. Gọi tĩnh từ bất kỳ Service nào, ví dụ:
 *   AuditLogger::log('login_success', 'users', (string) $user['id']);
 */
class AuditLogger
{
    public static function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?array $oldValue = null,
        ?array $newValue = null
    ): void {
        $repository = new AuditLogRepository();
        $userId = Session::get('user_id');
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $repository->insert(
            $userId !== null ? (int) $userId : null,
            $action,
            $entityType,
            $entityId,
            $oldValue,
            $newValue,
            $ip
        );
    }
}
