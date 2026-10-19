<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/**
 * FN-08.02 - recommendations. Chống trùng 2 lớp: Service khoá dòng cảnh báo + exists() trong cùng transaction;
 * DB UNIQUE uq_rec_alert_rule (alert_id, recommendation_rule_id) - patch 002 (đóng DESIGN GAP D-1).
 */
class RecommendationRepository extends BaseRepository
{
    protected string $table = 'recommendations';

    public function exists(int $alertId, int $ruleId): bool
    {
        return (int) $this->scalarQuery(
            'SELECT COUNT(*) FROM recommendations WHERE alert_id = :a AND recommendation_rule_id = :r',
            ['a' => $alertId, 'r' => $ruleId]
        ) > 0;
    }

    public function forAlert(int $alertId): array
    {
        return $this->fetchAllQuery(
            'SELECT x.*, r.name AS rule_name, r.status AS rule_status, r.condition_type, r.recommendation_text AS rule_text_now
             FROM recommendations x JOIN recommendation_rules r ON r.id = x.recommendation_rule_id
             WHERE x.alert_id = :a ORDER BY x.recommendation_rule_id, x.id',
            ['a' => $alertId]
        );
    }
}
