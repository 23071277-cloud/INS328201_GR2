<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/** Bảng system_configs (FN-01.07). Chỉ đọc / sửa giá trị - không thêm, không xoá khoá. */
class SystemConfigRepository extends BaseRepository
{
    protected string $table = 'system_configs';

    public function value(string $key, ?string $default = null): ?string
    {
        $value = $this->scalarQuery('SELECT config_value FROM system_configs WHERE config_key = :k', ['k' => $key]);

        return $value === false || $value === null ? $default : (string) $value;
    }

    /** @return array<string,string> config_key => config_value */
    public function allValues(): array
    {
        return array_map('strval', array_column($this->fetchAllQuery('SELECT config_key, config_value FROM system_configs'), 'config_value', 'config_key'));
    }

    public function allRows(): array
    {
        return $this->fetchAllQuery('SELECT c.*, u.full_name AS updated_by_name FROM system_configs c LEFT JOIN users u ON u.id = c.updated_by ORDER BY c.id');
    }

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT c.*, u.full_name AS updated_by_name FROM system_configs c LEFT JOIN users u ON u.id = c.updated_by WHERE c.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
