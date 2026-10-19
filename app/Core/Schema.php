<?php

declare(strict_types=1);

namespace App\Core;

/**
 * STEP 4C - phiên bản schema của DB đang kết nối (một mã nguồn chạy được cả hai):
 *   v1 = energy_dss (29 bảng: tariffs, tariff_tiers, tariff_proposals, fixed_fee_rates, alert_status_history, alert_contact_logs,
 *        recommendation_rules.condition_params JSON)
 *   v2 = energy_dss_v2 (26 bảng 3NF: pricing_plans, pricing_tiers, alert_events, recommendation_rules.n_periods / utility_type)
 * Chỉ tầng Repository dùng lớp này để hấp thụ khác biệt; Service / Controller / View nhận cùng một dạng dữ liệu ở cả hai schema.
 * Nhận diện bằng sự tồn tại của bảng pricing_plans trong DB hiện tại; ghi nhớ theo tên DB (đổi DB trong test -> nhận diện lại).
 */
final class Schema
{
    /** @var array<string,bool> */
    private static array $cache = [];

    public static function isV2(): bool
    {
        $db = Database::connection();
        $name = (string) $db->query('SELECT DATABASE()')->fetchColumn();
        if (!array_key_exists($name, self::$cache)) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :s AND table_name = 'pricing_plans'");
            $stmt->execute(['s' => $name]);
            self::$cache[$name] = (int) $stmt->fetchColumn() > 0;
        }

        return self::$cache[$name];
    }
}
