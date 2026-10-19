<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use PDOException;

class UserRepository extends BaseRepository
{
    protected string $table = 'users';

    /** Cột an toàn để hiển thị - KHÔNG bao giờ chọn password_hash cho màn hình (STEP 15). */
    private const PUBLIC_COLUMNS = 'u.id, u.username, u.full_name, u.role, u.status, u.failed_login_count, u.locked_until,
        u.last_login_at, u.created_by, u.created_at, u.updated_at';

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** FN-01.03 danh sách: tìm theo username/họ tên, lọc role, trạng thái; phân trang. */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(u.username LIKE :q1 OR u.full_name LIKE :q2)';
            $params['q1'] = $params['q2'] = $this->likeParam($filters['q']);
        }
        if (($filters['role'] ?? '') !== '') {
            $where[] = 'u.role = :role';
            $params['role'] = $filters['role'];
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'u.status = :status';
            $params['status'] = $filters['status'];
        }

        return $this->paginateQuery(
            self::PUBLIC_COLUMNS . ', c.full_name AS created_by_name',
            'FROM users u LEFT JOIN users c ON c.id = u.created_by WHERE ' . implode(' AND ', $where),
            $params,
            'u.id',
            $page,
            $perPage
        );
    }

    /** Chi tiết để hiển thị (không có password_hash). */
    public function findPublic(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::PUBLIC_COLUMNS . ', c.full_name AS created_by_name
            FROM users u LEFT JOIN users c ON c.id = u.created_by WHERE u.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * V2: khoá (FOR UPDATE) mọi BQL đang hoạt động rồi mới đếm -> 2 BQL vô hiệu hoá nhau cùng lúc
     * không thể làm hệ thống mất BQL hoạt động cuối cùng (lệnh sau chờ, đếm lại sau khi lệnh trước commit).
     * @return int[] id các BQL hoạt động
     */
    public function lockActiveBqlIds(): array
    {
        return array_map('intval', array_column(
            $this->fetchAllQuery("SELECT id FROM users WHERE role = 'bql' AND status = 'hoat_dong' ORDER BY id FOR UPDATE"),
            'id'
        ));
    }

    public function usernameExists(string $username): bool
    {
        return $this->scalarQuery('SELECT COUNT(*) FROM users WHERE username = :u', ['u' => $username]) > 0;
    }

    /** Tạo tài khoản; trùng username (UNIQUE uq_users_username - chốt chặn cuối khi 2 người tạo cùng lúc) -> null. */
    public function insertUnlessDuplicate(array $data): ?int
    {
        try {
            return $this->insertRow($data);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }
    }

    public function incrementFailedLogin(int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE users SET failed_login_count = failed_login_count + 1 WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    public function resetFailedLogin(int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    /** [TL - BR-02] Khoá tạm sau N lần sai; thời lượng khoá là [ASSUMPTION - J-03]. */
    public function lockAccount(int $userId, string $lockedUntil): void
    {
        $stmt = $this->db->prepare(
            "UPDATE users SET status = 'khoa_tam', locked_until = :locked_until WHERE id = :id"
        );
        $stmt->execute(['locked_until' => $lockedUntil, 'id' => $userId]);
    }

    public function updateLastLogin(int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    /** Tự mở khoá khi đã hết thời gian khoá tạm [ASSUMPTION - J-03]. */
    public function unlockIfExpired(int $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE users
             SET status = 'hoat_dong', failed_login_count = 0, locked_until = NULL
             WHERE id = :id AND status = 'khoa_tam' AND locked_until IS NOT NULL AND locked_until <= NOW()"
        );
        $stmt->execute(['id' => $userId]);
    }
}
