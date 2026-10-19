<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Base cho mọi Repository: chỉ chứa truy cập dữ liệu (PDO prepared statement),
 * KHÔNG chứa nghiệp vụ (nghiệp vụ nằm ở Service).
 */
abstract class BaseRepository
{
    protected PDO $db;
    protected string $table;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function all(string $orderBy = 'id', string $direction = 'ASC'): array
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $orderBy = $this->sanitizeColumnName($orderBy);

        $stmt = $this->db->query("SELECT * FROM {$this->table} ORDER BY {$orderBy} {$direction}");

        return $stmt->fetchAll();
    }

    public function paginate(int $page = 1, int $perPage = 20, string $orderBy = 'id', string $direction = 'DESC'): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $orderBy = $this->sanitizeColumnName($orderBy);

        $total = (int) $this->db->query("SELECT COUNT(*) AS total FROM {$this->table}")->fetch()['total'];

        $stmt = $this->db->prepare("SELECT * FROM {$this->table} ORDER BY {$orderBy} {$direction} LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = :id");

        return $stmt->execute(['id' => $id]);
    }

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function inTransaction(): bool
    {
        return $this->db->inTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollBack(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /** Khoá dòng trong transaction đang mở (dùng trước khi sửa/ngừng để tránh ghi đè đồng thời). */
    public function findForUpdate(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1 FOR UPDATE");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Thêm bản ghi; khoá của $data là tên cột do Service quyết định (không lấy trực tiếp từ request). */
    public function insertRow(array $data): int
    {
        $columns = array_map([$this, 'sanitizeColumnName'], array_keys($data));
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $stmt = $this->db->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        ));
        $stmt->execute(array_combine($columns, array_values($data)));

        return (int) $this->db->lastInsertId();
    }

    public function updateById(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $sets = [];
        $params = ['__id' => $id];
        foreach ($data as $column => $value) {
            $column = $this->sanitizeColumnName((string) $column);
            $sets[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }

        $stmt = $this->db->prepare(sprintf('UPDATE %s SET %s WHERE id = :__id', $this->table, implode(', ', $sets)));
        $stmt->execute($params);
    }

    /**
     * Phân trang cho câu truy vấn có điều kiện. $fromWhere bắt đầu từ "FROM ...",
     * $params là tham số prepared; $orderBy do Repository viết cứng (không từ request).
     */
    protected function paginateQuery(string $select, string $fromWhere, array $params, string $orderBy, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$fromWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $lastPage = (int) max(1, ceil($total / $perPage));
        $page = min($page, $lastPage);

        $stmt = $this->db->prepare("SELECT {$select} {$fromWhere} ORDER BY {$orderBy} LIMIT :__limit OFFSET :__offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
        }
        $stmt->bindValue(':__limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':__offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /** Tham số LIKE an toàn: escape ký tự đại diện % _ \ do người dùng gõ. */
    protected function likeParam(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }

    protected function scalarQuery(string $sql, array $params = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    protected function fetchAllQuery(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Chống injection qua tên cột khi orderBy đến từ query string. */
    protected function sanitizeColumnName(string $column): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?: 'id';
    }
}
