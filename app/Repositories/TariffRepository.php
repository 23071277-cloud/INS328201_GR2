<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use App\Core\Schema;

/**
 * FN-03.01 - bảng tariffs (mỗi dòng = 1 phiên bản của chuỗi utility_type + usage_type)
 * và tariff_tiers (bậc nửa khoảng [tier_from, tier_to) - Schema Review A1).
 * [STEP 4C] Schema v2: phiên bản = pricing_plans (price_type utility, approval_status da_duyet), bậc = pricing_tiers.
 * Repository trả về CÙNG dạng dòng như v1 (business_unit_price, approved_by…) - Service / View không đổi.
 */
class TariffRepository extends BaseRepository
{
    protected string $table = 'tariffs';

    /**
     * Cột tính sẵn: version_no (thứ tự phiên bản trong chuỗi theo ngày hiệu lực),
     * lifecycle (vòng đời hiển thị - P2), tier_count, tên người tạo/duyệt.
     * $today truyền từ PHP (múi giờ ứng dụng), dùng 2 placeholder riêng (native prepare).
     */
    private function selectSql(): string
    {
        [$T, $TT, $FK] = [self::source(), self::tierTable(), self::tierKey()];

        return "SELECT t.*,
            (SELECT COUNT(*) FROM {$T} v WHERE v.utility_type = t.utility_type AND v.usage_type = t.usage_type
                AND (v.effective_date < t.effective_date OR (v.effective_date = t.effective_date AND v.id <= t.id))) AS version_no,
            (SELECT COUNT(*) FROM {$TT} tt WHERE tt.{$FK} = t.id) AS tier_count,
            CASE
                WHEN t.status = 'het_hieu_luc' THEN 'het_hieu_luc'
                WHEN t.effective_date > :today1 THEN 'sap_ap_dung'
                WHEN t.id = (SELECT c.id FROM {$T} c WHERE c.utility_type = t.utility_type AND c.usage_type = t.usage_type
                             AND c.status = 'hieu_luc' AND c.effective_date <= :today2
                             ORDER BY c.effective_date DESC, c.id DESC LIMIT 1) THEN 'dang_ap_dung'
                ELSE 'da_thay_the'
            END AS lifecycle,
            uc.full_name AS created_by_name, ua.full_name AS approved_by_name
            FROM {$T} t
            LEFT JOIN users uc ON uc.id = t.created_by
            LEFT JOIN users ua ON ua.id = t.approved_by";
    }

    /** @param array{utility_type:string,usage_type:string,lifecycle:string,effective_from:string,effective_to:string} $filters */
    public function search(array $filters, string $today, int $page, int $perPage): array
    {
        $where = [];
        $params = ['today1' => $today, 'today2' => $today];

        foreach (['utility_type', 'usage_type', 'lifecycle'] as $key) {
            if ($filters[$key] !== '') {
                $where[] = "x.{$key} = :{$key}";
                $params[$key] = $filters[$key];
            }
        }
        if ($filters['effective_from'] !== '') {
            $where[] = 'x.effective_date >= :effective_from';
            $params['effective_from'] = $filters['effective_from'];
        }
        if ($filters['effective_to'] !== '') {
            $where[] = 'x.effective_date <= :effective_to';
            $params['effective_to'] = $filters['effective_to'];
        }

        $fromWhere = 'FROM (' . $this->selectSql() . ') x' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        return $this->paginateQuery('x.*', $fromWhere, $params, 'x.utility_type ASC, x.usage_type DESC, x.effective_date DESC, x.id DESC', $page, $perPage);
    }

    public function findDetailed(int $id, string $today): ?array
    {
        $stmt = $this->db->prepare($this->selectSql() . ' WHERE t.id = :id');
        $stmt->execute(['today1' => $today, 'today2' => $today, 'id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Mọi phiên bản của một chuỗi, mới nhất trước (lịch sử trên trang chi tiết). */
    public function seriesVersions(string $utilityType, string $usageType, string $today): array
    {
        $stmt = $this->db->prepare(
            $this->selectSql() . ' WHERE t.utility_type = :utility_type AND t.usage_type = :usage_type ORDER BY t.effective_date DESC, t.id DESC'
        );
        $stmt->execute(['today1' => $today, 'today2' => $today, 'utility_type' => $utilityType, 'usage_type' => $usageType]);

        return $stmt->fetchAll();
    }

    /** Phiên bản Đang áp dụng / mới nhất của từng chuỗi (để Kế toán chọn khi đề xuất). */
    public function latestPerSeries(string $today): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM (' . $this->selectSql() . ") x
             WHERE x.id = (SELECT l.id FROM " . self::source() . " l WHERE l.utility_type = x.utility_type AND l.usage_type = x.usage_type
                           ORDER BY l.effective_date DESC, l.id DESC LIMIT 1)
             ORDER BY x.utility_type, x.usage_type DESC"
        );
        $stmt->execute(['today1' => $today, 'today2' => $today]);

        return $stmt->fetchAll();
    }

    public function tiersOf(int $tariffId): array
    {
        return $this->fetchAllQuery(
            'SELECT tier_order, tier_from, tier_to, unit_price FROM ' . self::tierTable() . ' WHERE ' . self::tierKey() . ' = :id ORDER BY tier_order',
            ['id' => $tariffId]
        );
    }

    /**
     * FN-05.02 - phiên bản biểu giá áp dụng cho kỳ [ASSUMPTION J-15, Q2]: cùng chuỗi, status hieu_luc,
     * ngày hiệu lực <= ngày tham chiếu (ngày kết thúc kỳ), mới nhất - cùng quy tắc "đang áp dụng" của FN-03 (P2).
     */
    public function effectiveAt(string $utilityType, string $usageType, string $date): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . self::source() . " x WHERE utility_type = :u AND usage_type = :s AND status = 'hieu_luc' AND effective_date <= :d
             ORDER BY effective_date DESC, id DESC LIMIT 1"
        );
        $stmt->execute(['u' => $utilityType, 's' => $usageType, 'd' => $date]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Khoá toàn bộ chuỗi trong transaction để tạo/sửa/duyệt phiên bản tuần tự. */
    public function lockSeries(string $utilityType, string $usageType): array
    {
        return $this->fetchAllQuery(
            Schema::isV2()
                ? "SELECT id, effective_date, created_at FROM pricing_plans WHERE price_type = 'utility' AND approval_status = 'da_duyet' AND utility_type = :u AND usage_type = :s ORDER BY effective_date DESC, id DESC FOR UPDATE"
                : 'SELECT id, effective_date, created_at FROM tariffs WHERE utility_type = :u AND usage_type = :s ORDER BY effective_date DESC, id DESC FOR UPDATE',
            ['u' => $utilityType, 's' => $usageType]
        );
    }

    /** P5: không có 2 phiên bản cùng ngày hiệu lực trong một chuỗi. */
    public function effectiveDateExists(string $utilityType, string $usageType, string $date, ?int $exceptId = null): bool
    {
        return (int) $this->scalarQuery(
            'SELECT COUNT(*) FROM ' . self::source() . ' x WHERE utility_type = :u AND usage_type = :s AND effective_date = :d AND id <> :except_id',
            ['u' => $utilityType, 's' => $usageType, 'd' => $date, 'except_id' => $exceptId ?? 0]
        ) > 0;
    }

    /** P11 safeguard: số phiên bản của chuỗi được tạo HOẶC sửa sau thời điểm $since (updated_at mặc định = lúc tạo). */
    public function countModifiedAfter(string $utilityType, string $usageType, string $since): int
    {
        return (int) $this->scalarQuery(
            'SELECT COUNT(*) FROM ' . self::source() . ' x WHERE utility_type = :u AND usage_type = :s AND updated_at > :since',
            ['u' => $utilityType, 's' => $usageType, 'since' => $since]
        );
    }

    /** @param array<int, array{tier_from:string,tier_to:?string,unit_price:string}> $tiers */
    public function replaceTiers(int $tariffId, array $tiers): void
    {
        $this->db->prepare('DELETE FROM ' . self::tierTable() . ' WHERE ' . self::tierKey() . ' = :id')->execute(['id' => $tariffId]);

        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::tierTable() . ' (' . self::tierKey() . ', tier_order, tier_from, tier_to, unit_price) VALUES (:tariff_id, :tier_order, :tier_from, :tier_to, :unit_price)'
        );
        foreach (array_values($tiers) as $index => $tier) {
            $stmt->execute([
                'tariff_id' => $tariffId,
                'tier_order' => $index + 1,
                'tier_from' => $tier['tier_from'],
                'tier_to' => $tier['tier_to'],
                'unit_price' => $tier['unit_price'],
            ]);
        }
    }
    // ------------------------------------------------------------------ [STEP 4C] hấp thụ khác biệt schema v1 / v2

    /** Cột v1 của tariffs (đúng thứ tự) <- biểu thức v2 trên pricing_plans. */
    private const V2_COLUMNS = 'id, utility_type, usage_type, vat_percent, flat_unit_price AS business_unit_price, effective_date, status,
        created_by, reviewed_by AS approved_by, created_at, updated_at';

    /** Nguồn "tariffs": bảng v1, hoặc bảng dẫn xuất từ pricing_plans (phiên bản utility ĐÃ DUYỆT) ở v2. */
    public static function source(): string
    {
        return Schema::isV2()
            ? "(SELECT " . self::V2_COLUMNS . " FROM pricing_plans WHERE price_type = 'utility' AND approval_status = 'da_duyet')"
            : 'tariffs';
    }

    public static function tierTable(): string
    {
        return Schema::isV2() ? 'pricing_tiers' : 'tariff_tiers';
    }

    public static function tierKey(): string
    {
        return Schema::isV2() ? 'pricing_plan_id' : 'tariff_id';
    }

    public function find(int $id): ?array
    {
        if (!Schema::isV2()) {
            return parent::find($id);
        }
        $row = $this->fetchAllQuery('SELECT * FROM ' . self::source() . ' x WHERE id = :id', ['id' => $id]);

        return $row[0] ?? null;
    }

    public function findForUpdate(int $id): ?array
    {
        if (!Schema::isV2()) {
            return parent::findForUpdate($id);
        }
        $row = $this->fetchAllQuery('SELECT ' . self::V2_COLUMNS . " FROM pricing_plans WHERE id = :id AND price_type = 'utility' AND approval_status = 'da_duyet' FOR UPDATE", ['id' => $id]);

        return $row[0] ?? null;
    }

    /** v2: phiên bản tạo trực tiếp (BQL) = dòng pricing_plans origin direct, đã duyệt. */
    public function insertRow(array $data): int
    {
        if (!Schema::isV2()) {
            return parent::insertRow($data);
        }
        $saved = $this->table;
        $this->table = 'pricing_plans';
        try {
            return parent::insertRow(self::toV2($data) + ['price_type' => 'utility', 'origin' => 'direct', 'approval_status' => 'da_duyet']);
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

    /** Tên cột v1 -> v2. */
    public static function toV2(array $data): array
    {
        $map = ['business_unit_price' => 'flat_unit_price', 'approved_by' => 'reviewed_by'];
        $out = [];
        foreach ($data as $column => $value) {
            $out[$map[$column] ?? $column] = $value;
        }

        return $out;
    }
}
