<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use App\Core\Schema;

/**
 * FN-08.01 - recommendation_rules (không xoá: FK RESTRICT từ recommendations; chỉ Tắt).
 * [STEP 4C] Schema v2: tham số là cột n_periods + utility_type (không còn JSON condition_params). Repository dựng lại
 * condition_params (cùng định dạng json_encode của Service) khi đọc và tách ra cột khi ghi -> Service / View / luật không đổi.
 */
class RecommendationRuleRepository extends BaseRepository
{
    protected string $table = 'recommendation_rules';

    /** @param array{status:string,condition_type:string} $filters */
    public function search(array $filters): array
    {
        $where = [];
        $params = [];
        foreach (['status', 'condition_type'] as $key) {
            if ($filters[$key] !== '') {
                $where[] = "r.{$key} = :{$key}";
                $params[$key] = $filters[$key];
            }
        }

        return array_map([$this, 'present'], $this->fetchAllQuery(
            'SELECT r.*, u.full_name AS created_by_name, (SELECT COUNT(*) FROM recommendations x WHERE x.recommendation_rule_id = r.id) AS used_count
             FROM recommendation_rules r LEFT JOIN users u ON u.id = r.created_by'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.status, r.id',
            $params
        ));
    }

    public function findWithUsage(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, u.full_name AS created_by_name, (SELECT COUNT(*) FROM recommendations x WHERE x.recommendation_rule_id = r.id) AS used_count
             FROM recommendation_rules r LEFT JOIN users u ON u.id = r.created_by WHERE r.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->present($row) : null;
    }

    public function activeRules(): array
    {
        return array_map([$this, 'present'], $this->fetchAllQuery("SELECT * FROM recommendation_rules WHERE status = 'bat' ORDER BY id"));
    }
    // ------------------------------------------------------------------ [STEP 4C] hấp thụ khác biệt schema v1 / v2

    public function find(int $id): ?array
    {
        $row = parent::find($id);

        return $row ? $this->present($row) : null;
    }

    public function findForUpdate(int $id): ?array
    {
        $row = parent::findForUpdate($id);

        return $row ? $this->present($row) : null;
    }

    public function insertRow(array $data): int
    {
        return parent::insertRow(self::toV2($data));
    }

    public function updateById(int $id, array $data): void
    {
        parent::updateById($id, self::toV2($data));
    }

    /** v2: condition_params (JSON do Service tạo) -> n_periods, utility_type. v1: giữ nguyên. */
    private static function toV2(array $data): array
    {
        if (!Schema::isV2() || !array_key_exists('condition_params', $data)) {
            return $data;
        }
        $params = json_decode((string) $data['condition_params'], true);
        $params = is_array($params) ? $params : [];
        unset($data['condition_params']);

        return $data + ['n_periods' => $params['n_periods'] ?? null, 'utility_type' => $params['utility_type'] ?? null];
    }

    /** v2: dựng lại condition_params đúng định dạng Service (json_encode, thứ tự n_periods rồi utility_type; không tham số = NULL). */
    private function present(array $row): array
    {
        if (!array_key_exists('n_periods', $row) || array_key_exists('condition_params', $row)) {
            return $row;
        }
        $params = [];
        if ($row['n_periods'] !== null) {
            $params['n_periods'] = (int) $row['n_periods'];
        }
        if ($row['utility_type'] !== null) {
            $params['utility_type'] = $row['utility_type'];
        }
        $out = [];
        foreach ($row as $column => $value) {
            if ($column === 'n_periods') {
                $out['condition_params'] = $params === [] ? null : json_encode($params);
            } elseif ($column !== 'utility_type') {
                $out[$column] = $value;
            }
        }

        return $out;
    }
}
