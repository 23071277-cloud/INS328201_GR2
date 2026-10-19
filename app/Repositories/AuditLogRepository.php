<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

class AuditLogRepository extends BaseRepository
{
    protected string $table = 'audit_logs';

    public function insert(
        ?int $userId,
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $oldValue,
        ?array $newValue,
        ?string $ip
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, created_at)
             VALUES (:user_id, :action, :entity_type, :entity_id, :old_value, :new_value, :ip_address, NOW())'
        );

        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_value' => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            'new_value' => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => $ip,
        ]);
    }

    /** Lịch sử audit của một đối tượng (vd FN-04.09 trang chi tiết chỉ số). Chỉ đọc. */
    public function forEntity(string $entityType, string $entityId): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*, u.full_name AS user_name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id
             WHERE l.entity_type = :t AND l.entity_id = :i ORDER BY l.created_at, l.id'
        );
        $stmt->execute(['t' => $entityType, 'i' => $entityId]);

        return $stmt->fetchAll();
    }

    /** Bản audit mới nhất của đối tượng trong các action cho trước (vd snapshot tính phí FN-05). */
    public function latestForEntity(string $entityType, string $entityId, array $actions): ?array
    {
        $params = ['t' => $entityType, 'i' => $entityId];
        $in = [];
        foreach (array_values($actions) as $k => $action) {
            $in[] = ':a' . $k;
            $params['a' . $k] = $action;
        }
        $stmt = $this->db->prepare(
            'SELECT l.*, u.full_name AS user_name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id
             WHERE l.entity_type = :t AND l.entity_id = :i AND l.action IN (' . implode(', ', $in) . ')
             ORDER BY l.id DESC LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * FN-01.06 tra cứu (STEP 15): lọc người thực hiện, hành động, loại / mã đối tượng, khoảng ngày, IP.
     * Chỉ đọc; mọi giá trị lọc qua prepared statement. Mới nhất trước.
     */
    public function search(array $f, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];
        $exact = ['user_id' => 'l.user_id', 'action' => 'l.action', 'entity_type' => 'l.entity_type', 'entity_id' => 'l.entity_id', 'ip_address' => 'l.ip_address'];
        foreach ($exact as $key => $column) {
            if (($f[$key] ?? '') !== '') {
                $where[] = "{$column} = :{$key}";
                $params[$key] = $f[$key];
            }
        }
        if (($f['date_from'] ?? '') !== '') {
            $where[] = 'l.created_at >= :date_from';
            $params['date_from'] = $f['date_from'] . ' 00:00:00';
        }
        if (($f['date_to'] ?? '') !== '') {
            $where[] = 'l.created_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
            $params['date_to'] = $f['date_to'];
        }

        return $this->paginateQuery(
            'l.id, l.user_id, l.action, l.entity_type, l.entity_id, l.old_value, l.new_value, l.ip_address, l.created_at, u.username, u.full_name AS user_name',
            'FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE ' . implode(' AND ', $where),
            $params,
            'l.id DESC',
            $page,
            $perPage
        );
    }

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT l.*, u.username, u.full_name AS user_name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Giá trị phân biệt cho dropdown bộ lọc (action / entity_type). $column do code viết cứng. */
    public function distinctValues(string $column): array
    {
        $column = $column === 'action' ? 'action' : 'entity_type';

        return array_column($this->fetchAllQuery("SELECT DISTINCT {$column} AS v FROM audit_logs ORDER BY v"), 'v');
    }

    public function paginate(int $page = 1, int $perPage = 20, string $orderBy = 'created_at', string $direction = 'DESC'): array
    {
        return parent::paginate($page, $perPage, $orderBy, $direction);
    }
}
