<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use App\Core\Schema;

/**
 * [BA Proposal J-16] nhật ký liên hệ cư dân của cảnh báo (bảng alert_contact_logs, patch 005) - BQL ghi tay, không gọi điện tự động.
 * [STEP 4C] Schema v2: alert_events event_type 'contact' (result = contact_result); trả về cùng dạng dòng như v1.
 */
class AlertContactLogRepository extends BaseRepository
{
    protected string $table = 'alert_contact_logs';

    /** Cột v1 của alert_contact_logs (đúng thứ tự) <- alert_events. */
    private const V2_COLUMNS = 'id, alert_id, contact_type, contact_time, contact_result AS result, note, created_by, created_at';

    private static function source(): string
    {
        return Schema::isV2() ? "(SELECT " . self::V2_COLUMNS . " FROM alert_events WHERE event_type = 'contact')" : 'alert_contact_logs';
    }

    public function forAlert(int $alertId): array
    {
        return $this->fetchAllQuery(
            'SELECT l.*, u.full_name AS created_by_name FROM ' . self::source() . ' l LEFT JOIN users u ON u.id = l.created_by
             WHERE l.alert_id = :a ORDER BY l.contact_time, l.id',
            ['a' => $alertId]
        );
    }

    public function find(int $id): ?array
    {
        if (!Schema::isV2()) {
            return parent::find($id);
        }

        return $this->fetchAllQuery('SELECT * FROM ' . self::source() . ' l WHERE id = :id', ['id' => $id])[0] ?? null;
    }

    /** v2: ghi sự kiện 'contact' vào alert_events (created_at mặc định = lúc ghi nhận). */
    public function insertRow(array $data): int
    {
        if (!Schema::isV2()) {
            return parent::insertRow($data);
        }
        $row = ['event_type' => 'contact'];
        foreach ($data as $column => $value) {
            $row[$column === 'result' ? 'contact_result' : $column] = $value;
        }
        $saved = $this->table;
        $this->table = 'alert_events';
        try {
            return parent::insertRow($row);
        } finally {
            $this->table = $saved;
        }
    }
}
