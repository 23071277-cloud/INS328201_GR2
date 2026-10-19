<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use App\Core\Schema;

/**
 * FN-03.03 / 03.04 - bảng tariff_proposals (proposed_data JSON: vat, đơn giá KD, bậc).
 * [STEP 4C] Schema v2: đề xuất = dòng pricing_plans origin 'proposal' (bậc ở pricing_tiers, không còn JSON). Repository trả về
 * CÙNG dạng dòng như v1 (tariff_id, reason, status, proposed_by, proposed_data dựng lại từ cột + bậc) -> Service / View không đổi.
 * Duyệt ở v2 = chính dòng đề xuất thành phiên bản hiệu lực (approveInPlace) - không nhân bản dữ liệu giá.
 */
class TariffProposalRepository extends BaseRepository
{
    protected string $table = 'tariff_proposals';

    /** Cột v1 của tariff_proposals (đúng thứ tự) + 2 cột phụ để dựng proposed_data ở v2. */
    private const V2_COLUMNS = "id, base_plan_id AS tariff_id, utility_type, usage_type, NULL AS proposed_data, effective_date, proposal_reason AS reason,
        approval_status AS status, created_by AS proposed_by, reviewed_by, reviewed_at, rejection_reason, created_at,
        vat_percent AS __vat, flat_unit_price AS __flat";

    private static function source(): string
    {
        return Schema::isV2()
            ? "(SELECT " . self::V2_COLUMNS . " FROM pricing_plans WHERE origin = 'proposal' AND price_type = 'utility')"
            : 'tariff_proposals';
    }

    private static function selectDetailed(): string
    {
        return 'SELECT p.*, up.full_name AS proposed_by_name, ur.full_name AS reviewed_by_name,
            t.effective_date AS base_effective_date
        FROM ' . self::source() . ' p
        LEFT JOIN users up ON up.id = p.proposed_by
        LEFT JOIN users ur ON ur.id = p.reviewed_by
        LEFT JOIN ' . TariffRepository::source() . ' t ON t.id = p.tariff_id';
    }

    /** @param array{status:string,utility_type:string} $filters */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        foreach (['status', 'utility_type'] as $key) {
            if ($filters[$key] !== '') {
                $where[] = "p.{$key} = :{$key}";
                $params[$key] = $filters[$key];
            }
        }

        $fromWhere = 'FROM ' . self::source() . ' p
            LEFT JOIN users up ON up.id = p.proposed_by
            LEFT JOIN users ur ON ur.id = p.reviewed_by'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $result = $this->paginateQuery(
            'p.*, up.full_name AS proposed_by_name, ur.full_name AS reviewed_by_name',
            $fromWhere,
            $params,
            "(p.status = 'cho_duyet') DESC, p.created_at DESC, p.id DESC",
            $page,
            $perPage
        );
        $result['data'] = array_map([$this, 'present'], $result['data']);

        return $result;
    }

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare(self::selectDetailed() . ' WHERE p.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->present($row) : null;
    }

    /** VAL-03.05 [ĐX]: đã có đề xuất Chờ duyệt cho chuỗi (tiện ích, loại sử dụng). */
    public function pendingExists(string $utilityType, string $usageType): bool
    {
        return (int) $this->scalarQuery(
            'SELECT COUNT(*) FROM ' . self::source() . " p WHERE utility_type = :u AND usage_type = :s AND status = 'cho_duyet'",
            ['u' => $utilityType, 's' => $usageType]
        ) > 0;
    }

    public function forTariff(int $tariffId): array
    {
        return $this->fetchAllQuery(
            'SELECT p.id, p.status, p.effective_date, p.created_at, up.full_name AS proposed_by_name
             FROM ' . self::source() . ' p LEFT JOIN users up ON up.id = p.proposed_by
             WHERE p.tariff_id = :id ORDER BY p.created_at DESC',
            ['id' => $tariffId]
        );
    }

    // ------------------------------------------------------------------ [STEP 4C] hấp thụ khác biệt schema v1 / v2

    public function find(int $id): ?array
    {
        if (!Schema::isV2()) {
            return parent::find($id);
        }
        $row = $this->fetchAllQuery('SELECT * FROM ' . self::source() . ' p WHERE id = :id', ['id' => $id])[0] ?? null;

        return $row ? $this->present($row) : null;
    }

    public function findForUpdate(int $id): ?array
    {
        if (!Schema::isV2()) {
            return parent::findForUpdate($id);
        }
        // v2: đề xuất và phiên bản cùng bảng pricing_plans -> khoá theo CÙNG thứ tự với lúc duyệt (chuỗi trước, dòng đề xuất sau)
        // để 2 BQL duyệt đồng thời: người sau chờ rồi thấy "đã được xử lý" (P12) thay vì deadlock 1213.
        $series = $this->fetchAllQuery("SELECT utility_type, usage_type FROM pricing_plans WHERE id = :id AND origin = 'proposal' AND price_type = 'utility'", ['id' => $id])[0] ?? null;
        if ($series === null) {
            return null;
        }
        (new TariffRepository())->lockSeries((string) $series['utility_type'], (string) $series['usage_type']);
        $row = $this->fetchAllQuery('SELECT ' . self::V2_COLUMNS . " FROM pricing_plans WHERE id = :id AND origin = 'proposal' AND price_type = 'utility' FOR UPDATE", ['id' => $id])[0] ?? null;

        return $row ? $this->present($row) : null;
    }

    /** v2: đề xuất mới = dòng pricing_plans (origin proposal, chờ duyệt, status NULL) + bậc ở pricing_tiers (thay JSON proposed_data). */
    public function insertRow(array $data): int
    {
        if (!Schema::isV2()) {
            return parent::insertRow($data);
        }
        $proposed = json_decode((string) ($data['proposed_data'] ?? ''), true);
        $proposed = is_array($proposed) ? $proposed : [];
        $saved = $this->table;
        $this->table = 'pricing_plans';
        try {
            $id = parent::insertRow([
                'price_type' => 'utility',
                'utility_type' => $data['utility_type'],
                'usage_type' => $data['usage_type'],
                'vat_percent' => $proposed['vat_percent'] ?? null,
                'flat_unit_price' => $proposed['business_unit_price'] ?? null,
                'effective_date' => $data['effective_date'],
                'origin' => 'proposal',
                'approval_status' => $data['status'] ?? 'cho_duyet',
                'status' => null,
                'base_plan_id' => $data['tariff_id'] ?? null,
                'proposal_reason' => $data['reason'] ?? null,
                'created_by' => $data['proposed_by'] ?? null,
            ]);
        } finally {
            $this->table = $saved;
        }
        (new TariffRepository())->replaceTiers($id, is_array($proposed['tiers'] ?? null) ? $proposed['tiers'] : []);

        return $id;
    }

    public function updateById(int $id, array $data): void
    {
        if (!Schema::isV2()) {
            parent::updateById($id, $data);

            return;
        }
        $map = ['status' => 'approval_status', 'tariff_id' => 'base_plan_id', 'reason' => 'proposal_reason', 'proposed_by' => 'created_by'];
        $out = [];
        foreach ($data as $column => $value) {
            $out[$map[$column] ?? $column] = $value;
        }
        $saved = $this->table;
        $this->table = 'pricing_plans';
        try {
            parent::updateById($id, $out);
        } finally {
            $this->table = $saved;
        }
    }

    /**
     * v2 (thiết kế DB_3NF_V2_DESIGN 3.1): duyệt = CHÍNH dòng đề xuất thành phiên bản hiệu lực với dữ liệu giá đã kiểm tra.
     * v1 không dùng (Service tạo phiên bản tariffs mới + đánh dấu đề xuất da_duyet như trước).
     */
    public function approvesInPlace(): bool
    {
        return Schema::isV2();
    }

    /** @param array{vat_percent:?string,business_unit_price:?string,effective_date:string,tiers:array} $payload đã qua TariffService::validatePayload */
    public function approveInPlace(int $id, array $payload, int $reviewerId, string $reviewedAt): void
    {
        $this->db->prepare(
            "UPDATE pricing_plans SET approval_status = 'da_duyet', status = 'hieu_luc', vat_percent = :vat, flat_unit_price = :flat, effective_date = :d,
                reviewed_by = :u, reviewed_at = :at
             WHERE id = :id AND origin = 'proposal' AND approval_status = 'cho_duyet'"
        )->execute(['vat' => $payload['vat_percent'], 'flat' => $payload['business_unit_price'], 'd' => $payload['effective_date'], 'u' => $reviewerId, 'at' => $reviewedAt, 'id' => $id]);
        (new TariffRepository())->replaceTiers($id, $payload['tiers']);
    }

    /** v2: dựng lại proposed_data (vat, đơn giá KD, bậc) đúng định dạng JSON của v1; bỏ cột phụ. */
    private function present(array $row): array
    {
        if (!array_key_exists('__vat', $row)) {
            return $row;
        }
        $tiers = array_map(static fn (array $t): array => ['tier_from' => $t['tier_from'], 'tier_to' => $t['tier_to'], 'unit_price' => $t['unit_price']],
            (new TariffRepository())->tiersOf((int) $row['id']));
        $row['proposed_data'] = json_encode(['vat_percent' => $row['__vat'], 'business_unit_price' => $row['__flat'], 'tiers' => $tiers], JSON_UNESCAPED_UNICODE);
        unset($row['__vat'], $row['__flat']);

        return $row;
    }
}
