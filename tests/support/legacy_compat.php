<?php

declare(strict_types=1);

/**
 * STEP 4C - CHỈ DÙNG TRONG TEST. Cho phép bộ test (viết cho schema v1) chạy NGUYÊN VĂN trên DB schema v2 (energy_dss_v2):
 *   $pdo = legacy_compat_install();   // thay cho $pdo = Database::connection();
 * - DB v1: trả về Database::connection() như cũ (không đổi gì).
 * - DB v2: tạo LegacyCompatPDO (cùng DSN) và gắn làm kết nối duy nhất của Database (reflection - không sửa app), để câu SQL
 *   CỦA TEST còn nhắc tên bảng v1 (tariffs, tariff_tiers, tariff_proposals, fixed_fee_rates, alert_status_history,
 *   alert_contact_logs, recommendation_rules.condition_params) được viết lại sang nguồn v2 tương đương - CÙNG biểu thức mà
 *   Repository dùng (TariffRepository::source(), FixedFeeRepository::source()...). App ở v2 không phát sinh tên bảng v1 nên
 *   SQL của app đi qua nguyên vẹn. Câu GHI vào bảng v1 không có ánh xạ an toàn -> ném lỗi rõ ràng (không âm thầm khác nghĩa).
 */

use App\Core\Database;
use App\Core\Schema;
use App\Repositories\FixedFeeRepository;
use App\Repositories\TariffRepository;

function legacy_compat_install(): PDO
{
    $plain = Database::connection();
    if (!Schema::isV2() || $plain instanceof LegacyCompatPDO) {
        return $plain;
    }
    $c = App\Core\Config::get('database');
    $pdo = new LegacyCompatPDO(sprintf('%s:host=%s;port=%s;dbname=%s;charset=%s', $c['driver'], $c['host'], $c['port'], $c['database'], $c['charset']),
        $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $prop = new ReflectionProperty(Database::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);

    return $pdo;
}

final class LegacyCompatPDO extends PDO
{
    /** @var array<string,int> số lần mỗi luật viết lại được dùng (để báo cáo) */
    public static array $used = [];

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        return parent::prepare(self::rewrite((string) $query), $options);
    }

    #[\ReturnTypeWillChange]
    public function query($query, $fetchMode = null, ...$args)
    {
        $query = self::rewrite((string) $query);

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$args);
    }

    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        return parent::exec(self::rewrite((string) $statement));
    }

    /** Nguồn đọc dạng v1 (bảng dẫn xuất) trên schema v2. */
    public static function readSources(): array
    {
        $approvedUtility = "SELECT id FROM pricing_plans WHERE price_type = 'utility' AND approval_status = 'da_duyet'";
        $tierJson = "JSON_EXTRACT(CONCAT('[', IFNULL((SELECT GROUP_CONCAT(JSON_OBJECT('tier_from', CAST(t.tier_from AS CHAR), 'tier_to', CAST(t.tier_to AS CHAR),
            'unit_price', CAST(t.unit_price AS CHAR)) ORDER BY t.tier_order) FROM pricing_tiers t WHERE t.pricing_plan_id = p.id), ''), ']'), '$')";

        return [
            'tariffs' => TariffRepository::source(),
            'tariff_tiers' => "(SELECT id, pricing_plan_id AS tariff_id, tier_order, tier_from, tier_to, unit_price FROM pricing_tiers WHERE pricing_plan_id IN ({$approvedUtility}))",
            'fixed_fee_rates' => FixedFeeRepository::source(),
            'tariff_proposals' => "(SELECT p.id, p.base_plan_id AS tariff_id, p.utility_type, p.usage_type,
                JSON_OBJECT('vat_percent', CAST(p.vat_percent AS CHAR), 'business_unit_price', CAST(p.flat_unit_price AS CHAR), 'tiers', {$tierJson}) AS proposed_data,
                p.effective_date, p.proposal_reason AS reason, p.approval_status AS status, p.created_by AS proposed_by, p.reviewed_by, p.reviewed_at,
                p.rejection_reason, p.created_at FROM pricing_plans p WHERE p.origin = 'proposal' AND p.price_type = 'utility')",
            'alert_status_history' => "(SELECT id, alert_id, old_status, new_status, old_priority, new_priority, note, created_by AS changed_by, created_at AS changed_at
                FROM alert_events WHERE event_type = 'state_change')",
            'alert_contact_logs' => "(SELECT id, alert_id, contact_type, contact_time, contact_result AS result, note, created_by, created_at
                FROM alert_events WHERE event_type = 'contact')",
            'recommendation_rules' => "(SELECT id, name, condition_type,
                IF(n_periods IS NULL AND utility_type IS NULL, NULL, CONCAT('{', CONCAT_WS(',', IF(n_periods IS NULL, NULL, CONCAT('\"n_periods\":', n_periods)),
                   IF(utility_type IS NULL, NULL, CONCAT('\"utility_type\":\"', utility_type, '\"'))), '}')) AS condition_params,
                recommendation_text, status, created_by, created_at FROM recommendation_rules)",
        ];
    }

    public static function rewrite(string $sql): string
    {
        if (!preg_match('/\b(tariffs|tariff_tiers|tariff_proposals|fixed_fee_rates|alert_status_history|alert_contact_logs|condition_params)\b/i', $sql)) {
            return $sql;
        }
        // --- GHI: chỉ các dạng có ánh xạ một-một an toàn
        $deletes = [
            'tariffs' => ['pricing_plans', "price_type = 'utility' AND approval_status = 'da_duyet'"],
            'fixed_fee_rates' => ['pricing_plans', "price_type = 'fixed_fee'"],
            'tariff_proposals' => ['pricing_plans', "origin = 'proposal'"],
            'alert_contact_logs' => ['alert_events', "event_type = 'contact'"],
            'alert_status_history' => ['alert_events', "event_type = 'state_change'"],
        ];
        foreach ($deletes as $legacy => [$table, $scope]) {
            if (preg_match('/^\s*DELETE\s+FROM\s+' . $legacy . '\s+WHERE\s+(.+)$/is', $sql, $m)) {
                self::$used["DELETE {$legacy}"] = (self::$used["DELETE {$legacy}"] ?? 0) + 1;

                return "DELETE FROM {$table} WHERE {$scope} AND (" . self::rewrite($m[1]) . ')';
            }
        }
        if (preg_match('/^\s*DELETE\s+FROM\s+tariff_tiers\s+WHERE\s+tariff_id\b(.*)$/is', $sql, $m)) {
            return 'DELETE FROM pricing_tiers WHERE pricing_plan_id' . $m[1];
        }
        if (preg_match('/^\s*INSERT\s+INTO\s+tariff_tiers\s*\(\s*tariff_id\s*,(.*)$/is', $sql, $m)) {
            self::$used['INSERT tariff_tiers'] = (self::$used['INSERT tariff_tiers'] ?? 0) + 1;

            return 'INSERT INTO pricing_tiers (pricing_plan_id,' . $m[1];
        }
        if (preg_match('/^\s*(INSERT\s+INTO|UPDATE|REPLACE\s+INTO|DELETE\s+FROM)\s+(tariffs|tariff_tiers|tariff_proposals|fixed_fee_rates|alert_status_history|alert_contact_logs)\b/i', $sql, $m)) {
            throw new LogicException("[legacy_compat] Câu ghi v1 không có ánh xạ an toàn sang schema v2: {$m[1]} {$m[2]} — cần test riêng cho v2.");
        }
        // --- ĐỌC: FROM / JOIN <bảng v1> [AS] [alias]  ->  FROM / JOIN (<nguồn v2>) AS alias
        $sources = self::readSources();
        $names = 'tariffs|tariff_tiers|tariff_proposals|fixed_fee_rates|alert_status_history|alert_contact_logs';
        if (stripos($sql, 'condition_params') !== false) {
            $names .= '|recommendation_rules';
        }
        $reserved = '/^(WHERE|ON|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|ORDER|GROUP|LIMIT|HAVING|UNION|SET|AND|OR|USING|FOR|LOCK|WINDOW)$/i';

        return (string) preg_replace_callback('/\b(FROM|JOIN)\s+(' . $names . ')\b(\s+(?:AS\s+)?([a-z_][a-z0-9_]*))?/i', static function (array $m) use ($sources, $reserved): string {
            $legacy = strtolower($m[2]);
            $alias = isset($m[4]) && !preg_match($reserved, $m[4]) ? $m[4] : null;
            self::$used["READ {$legacy}"] = (self::$used["READ {$legacy}"] ?? 0) + 1;

            return $m[1] . ' ' . $sources[$legacy] . ' AS ' . ($alias ?? $legacy) . ($alias === null && isset($m[3]) ? $m[3] : '');
        }, $sql);
    }
}
