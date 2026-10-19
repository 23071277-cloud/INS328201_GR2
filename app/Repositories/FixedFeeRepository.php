<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use App\Core\Schema;

/**
 * FN-03.02 - bảng fixed_fee_rates (mỗi loại phí là một chuỗi phiên bản theo ngày hiệu lực).
 * [STEP 4C] Schema v2: pricing_plans price_type fixed_fee (unit_price = flat_unit_price); trả về cùng dạng dòng như v1.
 */
class FixedFeeRepository extends BaseRepository
{
    protected string $table = 'fixed_fee_rates';

    /** Mọi phiên bản kèm vòng đời tính theo ngày (P2) và tên người tạo; lọc theo loại phí nếu có. */
    public function listWithLifecycle(string $today, string $feeType = ''): array
    {
        $params = ['today1' => $today, 'today2' => $today];
        $F = self::source();
        $where = '';
        if ($feeType !== '') {
            $where = ' WHERE f.fee_type = :fee_type';
            $params['fee_type'] = $feeType;
        }

        return $this->fetchAllQuery(
            "SELECT f.*, u.full_name AS created_by_name,
                CASE
                    WHEN f.status = 'het_hieu_luc' THEN 'het_hieu_luc'
                    WHEN f.effective_date > :today1 THEN 'sap_ap_dung'
                    WHEN f.id = (SELECT c.id FROM {$F} c WHERE c.fee_type = f.fee_type AND c.status = 'hieu_luc'
                                 AND c.effective_date <= :today2 ORDER BY c.effective_date DESC, c.id DESC LIMIT 1) THEN 'dang_ap_dung'
                    ELSE 'da_thay_the'
                END AS lifecycle
             FROM {$F} f LEFT JOIN users u ON u.id = f.created_by{$where}
             ORDER BY f.fee_type, f.effective_date DESC, f.id DESC",
            $params
        );
    }

    /** FN-05.03 - đơn giá áp dụng cho kỳ (cùng quy tắc với TariffRepository::effectiveAt, [ASSUMPTION J-15]). */
    public function effectiveAt(string $feeType, string $date): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . self::source() . " x WHERE fee_type = :t AND status = 'hieu_luc' AND effective_date <= :d
             ORDER BY effective_date DESC, id DESC LIMIT 1"
        );
        $stmt->execute(['t' => $feeType, 'd' => $date]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function lockSeries(string $feeType): array
    {
        return $this->fetchAllQuery(
            Schema::isV2() ? "SELECT id FROM pricing_plans WHERE price_type = 'fixed_fee' AND fee_type = :t FOR UPDATE" : 'SELECT id FROM fixed_fee_rates WHERE fee_type = :t FOR UPDATE',
            ['t' => $feeType]
        );
    }

    public function effectiveDateExists(string $feeType, string $date, ?int $exceptId = null): bool
    {
        return (int) $this->scalarQuery(
            'SELECT COUNT(*) FROM ' . self::source() . ' x WHERE fee_type = :t AND effective_date = :d AND id <> :except_id',
            ['t' => $feeType, 'd' => $date, 'except_id' => $exceptId ?? 0]
        ) > 0;
    }
    // ------------------------------------------------------------------ [STEP 4C] hấp thụ khác biệt schema v1 / v2

    /** Cột v1 của fixed_fee_rates (đúng thứ tự) <- biểu thức v2 trên pricing_plans. */
    private const V2_COLUMNS = 'id, fee_type, flat_unit_price AS unit_price, effective_date, status, created_by, created_at';

    public static function source(): string
    {
        return Schema::isV2() ? "(SELECT " . self::V2_COLUMNS . " FROM pricing_plans WHERE price_type = 'fixed_fee')" : 'fixed_fee_rates';
    }

    public function find(int $id): ?array
    {
        if (!Schema::isV2()) {
            return parent::find($id);
        }

        return $this->fetchAllQuery('SELECT * FROM ' . self::source() . ' x WHERE id = :id', ['id' => $id])[0] ?? null;
    }

    public function findForUpdate(int $id): ?array
    {
        if (!Schema::isV2()) {
            return parent::findForUpdate($id);
        }

        return $this->fetchAllQuery('SELECT ' . self::V2_COLUMNS . " FROM pricing_plans WHERE id = :id AND price_type = 'fixed_fee' FOR UPDATE", ['id' => $id])[0] ?? null;
    }

    /** v2: phí cố định = dòng pricing_plans price_type fixed_fee, tạo trực tiếp, đã duyệt. */
    public function insertRow(array $data): int
    {
        if (!Schema::isV2()) {
            return parent::insertRow($data);
        }
        $saved = $this->table;
        $this->table = 'pricing_plans';
        try {
            return parent::insertRow(self::toV2($data) + ['price_type' => 'fixed_fee', 'origin' => 'direct', 'approval_status' => 'da_duyet']);
        } finally {
            $this->table = $saved;
        }
    }

    public function updateById(int $id, array $data): void
    {
        if (!Schema::isV2()) {
            parent::updateById($id, $data);

            return;
        }
        $saved = $this->table;
        $this->table = 'pricing_plans';
        try {
            parent::updateById($id, self::toV2($data));
        } finally {
            $this->table = $saved;
        }
    }

    public static function toV2(array $data): array
    {
        $out = [];
        foreach ($data as $column => $value) {
            $out[$column === 'unit_price' ? 'flat_unit_price' : $column] = $value;
        }

        return $out;
    }
}
