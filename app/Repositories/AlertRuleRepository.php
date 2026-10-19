<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/** FN-07.01 - alert_rules (không xoá: rule đã sinh cảnh báo chỉ Tắt). */
class AlertRuleRepository extends BaseRepository
{
    protected string $table = 'alert_rules';

    public const CONFIG_FIELDS = ['measure_type', 'utility_type', 'compare_base', 'operator', 'threshold_value', 'scope_type', 'scope_value'];

    /** @param array{status:string,utility_type:string,measure_type:string} $filters */
    public function search(array $filters): array
    {
        $where = [];
        $params = [];
        foreach (['status', 'utility_type', 'measure_type'] as $key) {
            if ($filters[$key] !== '') {
                $where[] = "r.{$key} = :{$key}";
                $params[$key] = $filters[$key];
            }
        }

        return $this->fetchAllQuery(
            'SELECT r.*, u.full_name AS created_by_name, (SELECT COUNT(*) FROM alerts a WHERE a.alert_rule_id = r.id) AS alert_count
             FROM alert_rules r LEFT JOIN users u ON u.id = r.created_by'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.status, r.utility_type, r.id',
            $params
        );
    }

    /** [ĐX] không trùng rule hoàn toàn giống nhau (đang Bật). */
    public function duplicateExists(array $config, int $exceptId = 0): bool
    {
        $where = ["status = 'bat'", 'id <> :x'];
        $params = ['x' => $exceptId];
        foreach (self::CONFIG_FIELDS as $field) {
            if ($config[$field] === null) {
                $where[] = "{$field} IS NULL";
            } else {
                $where[] = "{$field} = :{$field}";
                $params[$field] = $config[$field];
            }
        }

        return (int) $this->scalarQuery('SELECT COUNT(*) FROM alert_rules WHERE ' . implode(' AND ', $where), $params) > 0;
    }

    public function activeRules(?int $ruleId = null): array
    {
        return $ruleId === null
            ? $this->fetchAllQuery("SELECT * FROM alert_rules WHERE status = 'bat' ORDER BY id")
            : $this->fetchAllQuery("SELECT * FROM alert_rules WHERE status = 'bat' AND id = :id", ['id' => $ruleId]);
    }
}
