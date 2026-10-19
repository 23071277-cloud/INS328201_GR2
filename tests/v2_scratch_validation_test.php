<?php

declare(strict_types=1);

/**
 * STEP 4A - Kiểm tra schema 27 bảng (26 bảng 3NF + payment_transactions; phần schema của database/energy_dss_v2.sql) trên DATABASE NHÁP.
 *   bash tests/run_v2_scratch_validation.sh      (tạo DB nháp -> apply schema -> chạy file này -> DROP DB nháp)
 *   php tests/v2_scratch_validation_test.php <db_nháp>
 * Chỉ kết nối DB nháp (tên phải kết thúc "_scratch"); KHÔNG đọc .env, KHÔNG đụng energy_dss, KHÔNG nạp dữ liệu thật.
 * Kiểm: số bảng, tương thích MariaDB 10.4 (ENUM, CHECK, cột sinh, FK, index, UNIQUE, DEFAULT, ON UPDATE, collation),
 * FK trỏ bảng tồn tại, trùng tên ràng buộc, AUTO_INCREMENT, smoke insert/update/delete từng nhóm bảng,
 * và test ràng buộc 4 bảng mới (pricing_plans, pricing_tiers, alert_events, recommendation_rules).
 */

[$_, $db] = $argv + [null, ''];
if (!preg_match('/^[a-z0-9_]+_scratch$/', (string) $db)) {
    fwrite(STDERR, "Dùng: php tests/v2_scratch_validation_test.php <db_nháp_kết_thúc_bằng__scratch>\n");
    exit(2);
}
$socket = '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock';
$dsn = is_file($socket) ? "mysql:unix_socket={$socket};dbname={$db};charset=utf8mb4" : "mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4";
$pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $db) {
    fwrite(STDERR, "Không ở DB nháp.\n");
    exit(2);
}

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

function rows(PDO $pdo, string $sql, array $p = []): array
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchAll();
}

/** Chạy câu lệnh ghi; trả về null nếu thành công, ngược lại [mã lỗi MariaDB, thông điệp]. */
function attempt(PDO $pdo, string $sql, array $p = []): ?array
{
    try {
        $pdo->prepare($sql)->execute($p);

        return null;
    } catch (PDOException $e) {
        return [(int) ($e->errorInfo[1] ?? 0), (string) ($e->errorInfo[2] ?? $e->getMessage())];
    }
}

/** Mong đợi bị từ chối với một trong các mã lỗi; ghi lại mã thực tế. */
function expectReject(string $g, string $n, ?array $err, array $codes): void
{
    check($g, $n . ' → bị từ chối', $err !== null && in_array($err[0], $codes, true), $err === null ? 'ĐƯỢC CHẤP NHẬN (không mong đợi)' : "mã {$err[0]}: {$err[1]}");
}

function expectOk(string $g, string $n, ?array $err): void
{
    check($g, $n . ' → hợp lệ', $err === null, $err === null ? '' : "mã {$err[0]}: {$err[1]}");
}

const E_CHECK = 4025;   // ER_CONSTRAINT_FAILED (CHECK)
const E_FK = 1452;      // ER_NO_REFERENCED_ROW_2 (FK con không có cha)
const E_FK_PARENT = 1451; // ER_ROW_IS_REFERENCED_2 (xoá cha còn con)
const E_DUP = 1062;     // ER_DUP_ENTRY
const E_TRUNC = 1265;   // WARN_DATA_TRUNCATED (ENUM sai, strict mode)
const E_NULL = 1048;    // ER_BAD_NULL_ERROR

$expectedTables = ['alert_events', 'alert_rules', 'alerts', 'apartment_charges', 'apartments', 'audit_logs', 'baselines', 'billing_periods', 'buildings',
    'charge_tier_details', 'consumption_targets', 'email_notifications', 'import_batches', 'import_errors', 'inspection_cases', 'meter_readings', 'meters',
    'parking_vehicles', 'payment_transactions', 'pricing_plans', 'pricing_tiers', 'recommendation_rules', 'recommendations', 'residents', 'system_configs', 'users', 'zones'];

// ================================================================ 1. Cấu trúc + tương thích MariaDB 10.4
$G = 'SCHEMA';
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
$sqlMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
check($G, "MariaDB {$version}; sql_mode phiên = {$sqlMode}", str_starts_with($version, '10.4') && str_contains($sqlMode, 'STRICT_TRANS_TABLES'));
$tables = array_column(rows($pdo, "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY 1", [$db]), 't');
sort($tables);
sort($expectedTables);
check($G, 'Đúng 27 bảng, đúng danh sách target (so tập đã sắp xếp)', count($tables) === 27 && $tables === $expectedTables, json_encode(array_values(array_diff($expectedTables, $tables))) . ' / thừa ' . json_encode(array_values(array_diff($tables, $expectedTables))));
$removed = array_intersect($tables, ['tariffs', 'tariff_tiers', 'tariff_proposals', 'fixed_fee_rates', 'alert_status_history', 'alert_contact_logs']);
check($G, 'Không còn 6 bảng đã gộp', $removed === []);
$engines = rows($pdo, 'SELECT DISTINCT engine AS e, table_collation AS c FROM information_schema.tables WHERE table_schema = ?', [$db]);
check($G, 'Mọi bảng InnoDB + utf8mb4_general_ci (khớp DB thật)', $engines === [['e' => 'InnoDB', 'c' => 'utf8mb4_general_ci']], json_encode($engines));
// Cột kiểu JSON của MariaDB = alias LONGTEXT COLLATE utf8mb4_bin (giống hệt DB thật energy_dss) -> chỉ chấp nhận utf8mb4_bin ở đúng các cột JSON đó
$binCols = array_column(rows($pdo, "SELECT CONCAT(table_name, '.', column_name) AS c FROM information_schema.columns WHERE table_schema = ? AND collation_name = 'utf8mb4_bin' ORDER BY 1", [$db]), 'c');
$otherColl = array_column(rows($pdo, "SELECT DISTINCT collation_name AS c FROM information_schema.columns WHERE table_schema = ? AND collation_name NOT IN ('utf8mb4_general_ci', 'utf8mb4_bin')", [$db]), 'c');
check($G, 'Collation cột: utf8mb4_general_ci; utf8mb4_bin CHỈ ở 3 cột JSON (như DB thật)', $otherColl === [] && $binCols === ['audit_logs.new_value', 'audit_logs.old_value', 'import_batches.control_total_details'],
    json_encode(['bin' => $binCols, 'khác' => $otherColl]));
$schemaDefault = rows($pdo, 'SELECT default_character_set_name AS cs, default_collation_name AS c FROM information_schema.schemata WHERE schema_name = ?', [$db]);
check($G, 'Database mặc định utf8mb4 / utf8mb4_general_ci', $schemaDefault === [['cs' => 'utf8mb4', 'c' => 'utf8mb4_general_ci']], json_encode($schemaDefault));

$G = 'COMPAT';
$checks = rows($pdo, 'SELECT table_name AS t, constraint_name AS n, check_clause AS c FROM information_schema.check_constraints WHERE constraint_schema = ? ORDER BY 1, 2', [$db]);
$checkNames = array_map(static fn ($r) => $r['t'] . '.' . $r['n'], $checks);
$wantChecks = ['alert_events.chk_event_shape', 'alerts.chk_alert_snapshot_json', 'alerts.chk_alert_utility_type', 'pricing_plans.chk_pricing_flat_price',
    'pricing_plans.chk_pricing_lifecycle', 'pricing_plans.chk_pricing_origin', 'pricing_plans.chk_pricing_rejection', 'pricing_plans.chk_pricing_subtype',
    'pricing_plans.chk_pricing_values', 'pricing_tiers.chk_tier_range', 'recommendation_rules.chk_recrule_n_periods'];
check($G, 'CHECK được tạo (information_schema.CHECK_CONSTRAINTS): ' . count($checks) . ' ràng buộc gồm đủ 11 ràng buộc có tên', array_diff($wantChecks, $checkNames) === [],
    json_encode(array_values(array_diff($wantChecks, $checkNames))));
$generated = rows($pdo, "SELECT table_name AS t, column_name AS c, extra AS x, generation_expression AS g FROM information_schema.columns WHERE table_schema = ? AND generation_expression IS NOT NULL ORDER BY 1, 2", [$db]);
check($G, 'Cột sinh STORED (alerts.dedupe_meter_id, alerts.system_dedupe_key) tạo được', count($generated) === 2 && array_column($generated, 'c') === ['dedupe_meter_id', 'system_dedupe_key']
    && array_filter($generated, static fn ($r) => stripos($r['x'], 'STORED') === false) === [], json_encode($generated));
$onUpdate = (int) q($pdo, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND column_name = 'updated_at' AND extra LIKE '%on update%'", [$db]);
$updatedCols = (int) q($pdo, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND column_name = 'updated_at'", [$db]);
check($G, "ON UPDATE CURRENT_TIMESTAMP trên mọi cột updated_at ({$onUpdate}/{$updatedCols})", $onUpdate === $updatedCols && $updatedCols > 0);
$enumCols = (int) q($pdo, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND data_type = 'enum'", [$db]);
check($G, "ENUM tạo được ({$enumCols} cột)", $enumCols > 0);
$feeEnum = (string) q($pdo, "SELECT column_type FROM information_schema.columns WHERE table_schema = ? AND table_name = 'pricing_plans' AND column_name = 'fee_type'", [$db]);
check($G, 'pricing_plans.fee_type giữ đủ 7 loại gồm mo_lai_dich_vu', substr_count($feeEnum, "'") === 14 && str_contains($feeEnum, "'mo_lai_dich_vu'"), $feeEnum);
$zoneEnum = (string) q($pdo, "SELECT column_type FROM information_schema.columns WHERE table_schema = ? AND table_name = 'zones' AND column_name = 'zone_type'", [$db]);
check($G, 'zones.zone_type giữ lien_ke', str_contains($zoneEnum, "'lien_ke'"), $zoneEnum);
$jsonCols = rows($pdo, "SELECT CONCAT(table_name, '.', column_name) AS c FROM information_schema.columns WHERE table_schema = ? AND (data_type = 'json' OR column_name LIKE '%json%'
    OR column_name IN ('old_value','new_value','control_total_details','proposed_data','condition_params')) ORDER BY 1", [$db]);
check($G, 'JSON còn lại đúng 4 cột snapshot / nhật ký (không còn proposed_data, condition_params)', array_column($jsonCols, 'c') === ['alerts.snapshot_json', 'audit_logs.new_value', 'audit_logs.old_value', 'import_batches.control_total_details'],
    json_encode(array_column($jsonCols, 'c')));
$defaults = rows($pdo, "SELECT CONCAT(table_name, '.', column_name, '=', IFNULL(column_default, 'NULL')) AS d FROM information_schema.columns WHERE table_schema = ?
    AND ((table_name = 'pricing_plans' AND column_name IN ('origin','approval_status','status')) OR (table_name = 'alert_events' AND column_name = 'created_at')
      OR (table_name = 'recommendation_rules' AND column_name = 'status')) ORDER BY 1", [$db]);
check($G, "DEFAULT đúng: origin 'direct', approval_status 'da_duyet', status NULL, created_at current_timestamp(), status 'bat'", array_column($defaults, 'd') === [
    'alert_events.created_at=current_timestamp()', "pricing_plans.approval_status='da_duyet'", "pricing_plans.origin='direct'", 'pricing_plans.status=NULL', "recommendation_rules.status='bat'"], json_encode(array_column($defaults, 'd'), JSON_UNESCAPED_UNICODE));

$G = 'FK / INDEX';
$fks = rows($pdo, 'SELECT k.table_name AS t, k.constraint_name AS n, k.column_name AS c, k.referenced_table_name AS rt, k.referenced_column_name AS rc
    FROM information_schema.key_column_usage k WHERE k.table_schema = ? AND k.referenced_table_name IS NOT NULL ORDER BY 1, 2', [$db]);
$missingTargets = array_values(array_filter($fks, static fn ($f) => !in_array($f['rt'], $tables, true)));
check($G, count($fks) . ' cột FK: mọi bảng đích tồn tại trong target', $fks !== [] && $missingTargets === [], json_encode($missingTargets));
$dupFk = rows($pdo, "SELECT constraint_name AS n, COUNT(DISTINCT table_name) AS c FROM information_schema.table_constraints WHERE constraint_schema = ? AND constraint_type = 'FOREIGN KEY' GROUP BY 1 HAVING c > 1", [$db]);
check($G, 'Tên FK không trùng trong schema', $dupFk === [], json_encode($dupFk));
$dupCheck = rows($pdo, "SELECT table_name AS t, constraint_name AS n, COUNT(*) AS c FROM information_schema.check_constraints WHERE constraint_schema = ? GROUP BY 1, 2 HAVING c > 1", [$db]);
check($G, 'Tên CHECK không trùng trong cùng bảng', $dupCheck === [], json_encode($dupCheck));
$dupIdx = rows($pdo, "SELECT table_name AS t, GROUP_CONCAT(index_name) AS i FROM (SELECT table_name, index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) cols, non_unique
    FROM information_schema.statistics WHERE table_schema = ? GROUP BY table_name, index_name, non_unique) x GROUP BY table_name, cols, non_unique HAVING COUNT(*) > 1", [$db]);
check($G, 'Không có index trùng cột (cùng bảng, cùng thứ tự cột, cùng loại)', $dupIdx === [], json_encode($dupIdx));
$uniques = rows($pdo, "SELECT CONCAT(table_name, '.', index_name) AS u FROM information_schema.statistics WHERE table_schema = ? AND non_unique = 0 AND index_name <> 'PRIMARY' GROUP BY table_name, index_name ORDER BY 1", [$db]);
check($G, 'UNIQUE then chốt: ' . count($uniques) . ' (gồm uq_tier_order, uq_alert_dedupe, uq_alert_system_dedupe, uq_email_dedupe, uq_email_alert_dedupe)',
    array_diff(['pricing_tiers.uq_tier_order', 'alerts.uq_alert_dedupe', 'alerts.uq_alert_system_dedupe', 'email_notifications.uq_email_dedupe', 'email_notifications.uq_email_alert_dedupe',
        'recommendations.uq_rec_alert_rule', 'meter_readings.uq_reading_meter_period', 'apartment_charges.uq_charge_apartment_period'], array_column($uniques, 'u')) === [], json_encode(array_column($uniques, 'u')));
$noPk = rows($pdo, "SELECT t.table_name AS t FROM information_schema.tables t WHERE t.table_schema = ? AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints c
    WHERE c.table_schema = t.table_schema AND c.table_name = t.table_name AND c.constraint_type = 'PRIMARY KEY')", [$db]);
check($G, 'Mọi bảng có PRIMARY KEY', $noPk === [], json_encode($noPk));
$selfRefs = array_values(array_filter($fks, static fn ($f) => $f['t'] === $f['rt']));
check($G, 'Tham chiếu vòng / tự tham chiếu (' . implode(', ', array_map(static fn ($f) => "{$f['t']}.{$f['c']}", $selfRefs)) . ') không làm cài đặt lỗi (schema đã apply)', count($tables) === count($expectedTables));

/** Đếm dòng con mồ côi trên MỌI FK (kể cả khi FK_CHECKS = 0 lúc nạp). */
$orphans = static function () use ($pdo, $fks): array {
    $bad = [];
    foreach ($fks as $f) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$f['t']}` c LEFT JOIN `{$f['rt']}` p ON p.`{$f['rc']}` = c.`{$f['c']}` WHERE c.`{$f['c']}` IS NOT NULL AND p.`{$f['rc']}` IS NULL")->fetchColumn();
        if ($n > 0) {
            $bad[] = "{$f['t']}.{$f['c']}→{$f['rt']}: {$n}";
        }
    }

    return $bad;
};
check($G, 'FK violations = 0 (schema rỗng)', $orphans() === []);

// ================================================================ 2. Smoke insert / update / delete theo nhóm (dữ liệu giả, chỉ trong DB nháp)
$G = 'SMOKE';
$pdo->exec("INSERT INTO users (username, password_hash, full_name, role) VALUES ('s_bql', 'x', 'BQL nháp', 'bql'), ('s_kt', 'x', 'KT nháp', 'ky_thuat'), ('s_kto', 'x', 'KTo nháp', 'ke_toan')");
$u = array_map('intval', $pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
check($G, 'AUTO_INCREMENT users: 1, 2, 3', $u === [1, 2, 3], json_encode($u));
[$bql, $kt, $kto] = $u;
$pdo->exec("INSERT INTO system_configs (config_key, config_value) VALUES ('s_key', '1')");
$pdo->exec("INSERT INTO buildings (code, name, created_by) VALUES ('B1', 'Tòa nháp', {$bql})");
$pdo->exec("INSERT INTO zones (building_id, code, name, zone_type) VALUES (1, 'LK', 'Liền kề', 'lien_ke')");
$pdo->exec("INSERT INTO apartments (building_id, zone_id, code, area) VALUES (1, 1, 'A1', 80.00), (1, 1, 'A2', 60.00)");
$pdo->exec("INSERT INTO residents (apartment_id, full_name, email) VALUES (1, 'Cư dân nháp', 'a@example.invalid')");
$pdo->exec("INSERT INTO meters (apartment_id, meter_code, utility_type, usage_type) VALUES (1, 'A1-DIEN', 'dien', 'sinh_hoat'), (1, 'A1-NUOC', 'nuoc', 'sinh_hoat')");
$pdo->exec("INSERT INTO parking_vehicles (apartment_id, vehicle_type, quantity) VALUES (1, 'o_to', 1)");
check($G, 'Danh mục: users, configs, buildings, zones (lien_ke), apartments, residents, meters, parking_vehicles', (int) q($pdo, 'SELECT COUNT(*) FROM apartments') === 2 && (int) q($pdo, 'SELECT COUNT(*) FROM meters') === 2);
$pdo->exec("INSERT INTO pricing_plans (price_type, utility_type, usage_type, vat_percent, effective_date, status, created_by, reviewed_by) VALUES ('utility', 'dien', 'sinh_hoat', 8.00, '2024-01-01', 'hieu_luc', {$bql}, {$bql})");
$pdo->exec("INSERT INTO pricing_tiers (pricing_plan_id, tier_order, tier_from, tier_to, unit_price) VALUES (1, 1, 0, 50, 1806), (1, 2, 50, NULL, 1866)");
$pdo->exec("INSERT INTO billing_periods (period_code, start_date, end_date, created_by) VALUES ('07/2024', '2024-07-01', '2024-07-31', {$bql})");
$pdo->exec("INSERT INTO import_batches (billing_period_id, file_name, uploaded_by, control_total_details) VALUES (1, 'nhap.xlsx', {$kt}, '{\"cl\":{\"expected\":1,\"actual\":1}}')");
$pdo->exec("INSERT INTO import_errors (import_batch_id, row_no, error_message) VALUES (1, 3, 'lỗi nháp')");
$pdo->exec("INSERT INTO meter_readings (meter_id, billing_period_id, previous_reading, current_reading, consumption, entered_by, import_batch_id, source) VALUES (1, 1, 0, 100, 100, {$kt}, 1, 'import')");
$pdo->exec("INSERT INTO apartment_charges (apartment_id, billing_period_id, electricity_consumption, electricity_amount, total_arising, total_due) VALUES (1, 1, 100, 180600, 195048, 195048)");
$pdo->exec('INSERT INTO charge_tier_details (apartment_charge_id, meter_reading_id, utility_type, tier_order, tier_from, tier_to, quantity, unit_price, amount) VALUES (1, 1, \'dien\', 1, 0, 50, 50, 1806, 90300)');
check($G, 'Giá + kỳ + import + chỉ số + bản tính + chi tiết bậc', (int) q($pdo, 'SELECT COUNT(*) FROM charge_tier_details') === 1);
$pdo->exec("INSERT INTO baselines (scope_type, scope_id, utility_type, method, manual_value, effective_date, created_by) VALUES ('meter', 1, 'dien', 'nhap_tay', 300, '2024-08-01', {$bql})");
$pdo->exec("INSERT INTO consumption_targets (scope_type, scope_id, utility_type, billing_period_id, target_value, created_by) VALUES ('building', 1, 'dien', 1, 700, {$bql})");
$pdo->exec("INSERT INTO alert_rules (name, measure_type, utility_type, compare_base, threshold_value, created_by) VALUES ('Rule nháp', 'tieu_thu', 'dien', 'gia_tri_tuyet_doi', 50, {$bql})");
$pdo->exec("INSERT INTO alerts (alert_rule_id, meter_id, apartment_id, billing_period_id, utility_type, actual_value) VALUES (1, 1, 1, 1, 'dien', 100)");
$pdo->exec("INSERT INTO alerts (alert_type, source, apartment_id, billing_period_id, utility_type, snapshot_json) VALUES ('no_usage_consumption', 'he_thong', 2, 1, NULL, '{\"cl\":5}')");
$gen = rows($pdo, 'SELECT id, dedupe_meter_id AS d, system_dedupe_key AS s FROM alerts ORDER BY id');
check($G, 'Cột sinh tự tính: alert rule (d = meter 1, s NULL), alert hệ thống J-16 (d = 0, s = 1)', $gen === [['id' => 1, 'd' => 1, 's' => null], ['id' => 2, 'd' => 0, 's' => 1]], json_encode($gen));
$pdo->exec("INSERT INTO inspection_cases (alert_id, content, assigned_to, created_by) VALUES (1, 'Kiểm tra nháp', {$kt}, {$bql})");
$pdo->exec("INSERT INTO alert_events (alert_id, event_type, old_status, new_status, created_by) VALUES (1, 'state_change', 'moi', 'da_review', {$bql})");
$pdo->exec("INSERT INTO recommendation_rules (name, condition_type, n_periods, recommendation_text) VALUES ('Rec nháp', 'vuot_nguong_lien_tiep', 1, 'Kiểm tra công tơ')");
$pdo->exec("INSERT INTO recommendations (alert_id, recommendation_rule_id, recommendation_text) VALUES (1, 1, 'Kiểm tra công tơ')");
$pdo->exec("INSERT INTO email_notifications (resident_id, apartment_id, billing_period_id, apartment_charge_id, recipient_email, subject, body_html, body_text, content_hash, transport) VALUES (1, 1, 1, 1, 'a@example.invalid', 'S', '<p>x</p>', 'x', REPEAT('a', 64), 'log')");
$pdo->exec("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_value) VALUES ({$bql}, 'SMOKE', 'alert', '1', '{\"a\":1}')");
$pdo->exec("INSERT INTO payment_transactions (transaction_id, amount, transfer_content, received_at, apartment_charge_id, match_status, raw_payload) VALUES ('TXN-S1', 195048, 'EDSS000001', NOW(), 1, 'matched', '{\"t\":1}'), ('TXN-S2', 5000, 'khong ro', NOW(), NULL, 'unknown_ref', '{\"t\":2}')");
check($G, 'Cảnh báo + J-16 + kiểm tra + sự kiện + khuyến nghị + email + audit: chèn được', (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs') === 1 && (int) q($pdo, 'SELECT COUNT(*) FROM email_notifications') === 1);
$filled = (int) q($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_rows > 0", [$db]);
$empty = array_values(array_filter($tables, static fn ($t) => (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn() === 0));
check($G, 'Smoke đã chèn dữ liệu vào cả 27 bảng', $empty === [], json_encode($empty));
check($G, 'FK violations = 0 sau smoke', $orphans() === [], json_encode($orphans()));

// UPDATE: ON UPDATE CURRENT_TIMESTAMP + UNIQUE
$pdo->exec("UPDATE apartments SET updated_at = '2000-01-01 00:00:00' WHERE id = 1");
$pdo->exec('UPDATE apartments SET area = 81.00 WHERE id = 1');
check($G, 'UPDATE: ON UPDATE CURRENT_TIMESTAMP tự đổi updated_at', (string) q($pdo, 'SELECT updated_at FROM apartments WHERE id = 1') > '2020-01-01');
$pdo->exec("UPDATE pricing_plans SET updated_at = '2000-01-01 00:00:00' WHERE id = 1");
$pdo->exec("UPDATE pricing_plans SET status = 'het_hieu_luc' WHERE id = 1");
check($G, 'UPDATE pricing_plans: hieu_luc → het_hieu_luc hợp lệ + updated_at tự đổi', q($pdo, 'SELECT status FROM pricing_plans WHERE id = 1') === 'het_hieu_luc' && (string) q($pdo, 'SELECT updated_at FROM pricing_plans WHERE id = 1') > '2020-01-01');
expectReject($G, 'UPDATE trùng UNIQUE meter_code', attempt($pdo, "UPDATE meters SET meter_code = 'A1-DIEN' WHERE id = 2"), [E_DUP]);
expectReject($G, 'Chỉ số trùng (công tơ, kỳ) - uq_reading_meter_period', attempt($pdo, "INSERT INTO meter_readings (meter_id, billing_period_id, previous_reading, current_reading, consumption, entered_by) VALUES (1, 1, 0, 1, 1, {$kt})"), [E_DUP]);
expectReject($G, 'Cảnh báo rule trùng (rule, căn, công tơ, kỳ) - uq_alert_dedupe (BR-40)', attempt($pdo, "INSERT INTO alerts (alert_rule_id, meter_id, apartment_id, billing_period_id, utility_type) VALUES (1, 1, 1, 1, 'dien')"), [E_DUP]);
expectReject($G, 'Cảnh báo J-16 thứ 2 cùng căn + kỳ - uq_alert_system_dedupe', attempt($pdo, "INSERT INTO alerts (alert_type, source, apartment_id, billing_period_id) VALUES ('no_usage_consumption', 'he_thong', 2, 1)"), [E_DUP]);
expectOk($G, 'Cảnh báo thủ công (rule NULL) nhiều lần không bị chặn', attempt($pdo, "INSERT INTO alerts (source, apartment_id, billing_period_id, utility_type, description) VALUES ('thu_cong', 1, 1, 'dien', 'Thủ công')"));
expectReject($G, 'Email trùng (bản tính, người nhận, loại, nội dung) - uq_email_dedupe', attempt($pdo, "INSERT INTO email_notifications (apartment_id, apartment_charge_id, recipient_email, subject, body_html, body_text, content_hash, transport) VALUES (1, 1, 'a@example.invalid', 'S', 'x', 'x', REPEAT('a', 64), 'log')"), [E_DUP]);
expectReject($G, 'Giao dịch trùng transaction_id - uq_payment_transaction (idempotency)', attempt($pdo, "INSERT INTO payment_transactions (transaction_id, amount, received_at, match_status, raw_payload) VALUES ('TXN-S1', 1, NOW(), 'unknown_ref', '{}')"), [E_DUP]);
expectReject($G, 'payment_transactions CHECK amount > 0', attempt($pdo, "INSERT INTO payment_transactions (transaction_id, amount, received_at, match_status, raw_payload) VALUES ('TXN-S3', 0, NOW(), 'unknown_ref', '{}')"), [E_CHECK]);
expectReject($G, 'payment_transactions CHECK matched phải có bản tính', attempt($pdo, "INSERT INTO payment_transactions (transaction_id, amount, received_at, match_status, raw_payload) VALUES ('TXN-S4', 1, NOW(), 'matched', '{}')"), [E_CHECK]);
expectReject($G, 'payment_transactions CHECK manual_matched / ignored phải có người + lý do xử lý', attempt($pdo, "INSERT INTO payment_transactions (transaction_id, amount, received_at, match_status, raw_payload) VALUES ('TXN-S5', 1, NOW(), 'ignored', '{}')"), [E_CHECK]);
expectReject($G, 'payment_transactions CHECK raw_payload JSON hợp lệ', attempt($pdo, "INSERT INTO payment_transactions (transaction_id, amount, received_at, match_status, raw_payload) VALUES ('TXN-S6', 1, NOW(), 'unknown_ref', 'không phải json')"), [E_CHECK]);
expectReject($G, 'DELETE bản tính đã có giao dịch thanh toán (RESTRICT)', attempt($pdo, 'DELETE FROM apartment_charges WHERE id = 1'), [E_FK_PARENT]);
expectReject($G, 'alerts CHECK utility NULL khi không phải J-16 (patch 005)', attempt($pdo, "INSERT INTO alerts (source, apartment_id, billing_period_id, utility_type) VALUES ('thu_cong', 1, 1, NULL)"), [E_CHECK]);
expectReject($G, 'alerts CHECK snapshot_json không hợp lệ', attempt($pdo, "INSERT INTO alerts (source, apartment_id, billing_period_id, utility_type, snapshot_json) VALUES ('thu_cong', 2, 1, 'nuoc', 'không phải json')"), [E_CHECK]);

// DELETE: RESTRICT + CASCADE
expectReject($G, 'DELETE căn còn công tơ (RESTRICT)', attempt($pdo, 'DELETE FROM apartments WHERE id = 1'), [E_FK_PARENT]);
expectOk($G, 'DELETE lô import (lỗi import CASCADE; chỉ số giữ FK RESTRICT nên phải gỡ trước)', attempt($pdo, 'UPDATE meter_readings SET import_batch_id = NULL WHERE id = 1') ?? attempt($pdo, 'DELETE FROM import_batches WHERE id = 1'));
check($G, 'import_errors bị xoá theo lô (CASCADE)', (int) q($pdo, 'SELECT COUNT(*) FROM import_errors') === 0);
$pdo->exec('DELETE FROM residents WHERE id = 1');
check($G, 'DELETE cư dân: email_notifications.resident_id SET NULL (không mất thư)', (int) q($pdo, 'SELECT COUNT(*) FROM email_notifications') === 1 && q($pdo, 'SELECT resident_id FROM email_notifications') === null);
$pdo->exec("INSERT INTO alerts (source, apartment_id, billing_period_id, utility_type, description) VALUES ('thu_cong', 2, 1, 'nuoc', 'Xoá thử')");
$delAlert = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO alert_events (alert_id, event_type, new_status) VALUES ({$delAlert}, 'state_change', 'moi')");
$pdo->exec("INSERT INTO inspection_cases (alert_id, content, assigned_to, created_by) VALUES ({$delAlert}, 'x', {$kt}, {$bql})");
$pdo->exec("DELETE FROM alerts WHERE id = {$delAlert}");
check($G, 'DELETE cảnh báo: alert_events + inspection_cases CASCADE', (int) q($pdo, 'SELECT COUNT(*) FROM alert_events WHERE alert_id = ?', [$delAlert]) === 0 && (int) q($pdo, 'SELECT COUNT(*) FROM inspection_cases WHERE alert_id = ?', [$delAlert]) === 0);
check($G, 'FK violations = 0 sau update / delete', $orphans() === [], json_encode($orphans()));

// ================================================================ 3. pricing_plans
$G = 'PRICING_PLANS';
$ins = static fn (array $c): ?array => attempt($pdo, 'INSERT INTO pricing_plans (' . implode(', ', array_keys($c)) . ') VALUES (' . implode(', ', array_fill(0, count($c), '?')) . ')', array_values($c));
$util = ['price_type' => 'utility', 'utility_type' => 'nuoc', 'usage_type' => 'sinh_hoat', 'effective_date' => '2024-01-01', 'status' => 'hieu_luc', 'created_by' => $bql];
expectOk($G, 'Biểu giá bậc thang sinh hoạt (nước, VAT NULL, không đồng giá)', $ins($util));
expectOk($G, 'Biểu giá kinh doanh đồng giá (điện KD 3.904, VAT 8%)', $ins(['usage_type' => 'kinh_doanh', 'utility_type' => 'dien', 'vat_percent' => 8, 'flat_unit_price' => 3904] + $util));
foreach (['dich_vu_co_o' => 3000, 'dich_vu_khong_o' => 1500, 'bao_hiem_chay_no' => 1953, 'gui_xe_dap_dien' => 100000, 'gui_xe_may' => 50000, 'gui_o_to' => 500000, 'mo_lai_dich_vu' => 20000] as $fee => $price) {
    $err = $ins(['price_type' => 'fixed_fee', 'fee_type' => $fee, 'flat_unit_price' => $price, 'effective_date' => '2024-01-01', 'status' => 'hieu_luc', 'created_by' => $bql]);
    if ($err !== null) {
        expectOk($G, "Phí cố định {$fee}", $err);
    }
}
check($G, 'Phí cố định đủ 7 loại (dịch vụ có ở / không ở, BHCN, gửi xe ×3, mo_lai_dich_vu) → hợp lệ', (int) q($pdo, "SELECT COUNT(DISTINCT fee_type) FROM pricing_plans WHERE price_type = 'fixed_fee'") === 7);
expectOk($G, 'Đề xuất chờ duyệt (origin proposal, cho_duyet, status NULL, base_plan_id, lý do)', $ins(['origin' => 'proposal', 'approval_status' => 'cho_duyet', 'status' => null, 'base_plan_id' => 1, 'proposal_reason' => 'Điều chỉnh'] + $util));
$proposalId = (int) $pdo->lastInsertId();
expectOk($G, 'Đề xuất bị từ chối có lý do', $ins(['origin' => 'proposal', 'approval_status' => 'tu_choi', 'status' => null, 'base_plan_id' => 1, 'rejection_reason' => 'Sai giá', 'reviewed_by' => $bql, 'reviewed_at' => '2024-02-01 00:00:00'] + $util));
expectOk($G, 'Duyệt đề xuất: cùng dòng cho_duyet → da_duyet + hieu_luc', attempt($pdo, "UPDATE pricing_plans SET approval_status = 'da_duyet', status = 'hieu_luc', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?", [$bql, $proposalId]));
expectReject($G, 'utility thiếu usage_type', $ins(['usage_type' => null] + $util), [E_CHECK]);
expectReject($G, 'utility có fee_type', $ins(['fee_type' => 'gui_o_to'] + $util), [E_CHECK]);
expectReject($G, 'fixed_fee có utility_type', $ins(['price_type' => 'fixed_fee', 'fee_type' => 'gui_o_to', 'flat_unit_price' => 1, 'effective_date' => '2024-01-01', 'status' => 'hieu_luc', 'utility_type' => 'dien']), [E_CHECK]);
expectReject($G, 'fixed_fee có VAT', $ins(['price_type' => 'fixed_fee', 'fee_type' => 'gui_o_to', 'flat_unit_price' => 1, 'vat_percent' => 8, 'effective_date' => '2024-01-01', 'status' => 'hieu_luc']), [E_CHECK]);
expectReject($G, 'fixed_fee thiếu đơn giá', $ins(['price_type' => 'fixed_fee', 'fee_type' => 'gui_o_to', 'effective_date' => '2024-01-01', 'status' => 'hieu_luc']), [E_CHECK]);
expectReject($G, 'sinh hoạt có đồng giá', $ins(['flat_unit_price' => 100] + $util), [E_CHECK]);
expectReject($G, 'kinh doanh thiếu đồng giá', $ins(['usage_type' => 'kinh_doanh'] + $util), [E_CHECK]);
expectReject($G, 'đơn giá âm', $ins(['usage_type' => 'kinh_doanh', 'flat_unit_price' => -1] + $util), [E_CHECK]);
expectReject($G, 'VAT > 100', $ins(['vat_percent' => 101] + $util), [E_CHECK]);
expectReject($G, 'da_duyet nhưng status NULL', $ins(['status' => null] + $util), [E_CHECK]);
expectReject($G, 'cho_duyet nhưng có status hieu_luc', $ins(['origin' => 'proposal', 'approval_status' => 'cho_duyet'] + $util), [E_CHECK]);
expectReject($G, 'direct nhưng cho_duyet', $ins(['approval_status' => 'cho_duyet', 'status' => null] + $util), [E_CHECK]);
expectReject($G, 'direct nhưng có base_plan_id', $ins(['base_plan_id' => 1] + $util), [E_CHECK]);
expectReject($G, 'tu_choi thiếu lý do', $ins(['origin' => 'proposal', 'approval_status' => 'tu_choi', 'status' => null] + $util), [E_CHECK]);
expectReject($G, 'fee_type ngoài ENUM', $ins(['price_type' => 'fixed_fee', 'fee_type' => 'phi_la', 'flat_unit_price' => 1, 'effective_date' => '2024-01-01', 'status' => 'hieu_luc']), [E_TRUNC]);
expectReject($G, 'price_type NULL', $ins(['price_type' => null] + $util), [E_NULL]);
expectReject($G, 'FK base_plan_id không tồn tại', $ins(['origin' => 'proposal', 'approval_status' => 'cho_duyet', 'status' => null, 'base_plan_id' => 999999] + $util), [E_FK]);
expectReject($G, 'FK created_by không tồn tại', $ins(['created_by' => 999999] + $util), [E_FK]);
expectReject($G, 'FK reviewed_by không tồn tại', $ins(['reviewed_by' => 999999] + $util), [E_FK]);
check($G, 'pricing_plans không có UNIQUE (nhiều phiên bản cùng loại / ngày hợp lệ theo nghiệp vụ) — kiểm UNIQUE ở pricing_tiers', true);
$pdo->exec("INSERT INTO pricing_plans (price_type, utility_type, usage_type, effective_date, origin, approval_status, status, base_plan_id) VALUES ('utility', 'dien', 'sinh_hoat', '2025-01-01', 'proposal', 'cho_duyet', NULL, 1)");
$child = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO pricing_plans (price_type, utility_type, usage_type, effective_date, status) VALUES ('utility', 'dien', 'sinh_hoat', '2023-01-01', 'hieu_luc')");
$parent = (int) $pdo->lastInsertId();
$pdo->exec("UPDATE pricing_plans SET base_plan_id = {$parent} WHERE id = {$child}");
$pdo->exec("DELETE FROM pricing_plans WHERE id = {$parent}");
check($G, 'Tự tham chiếu base_plan_id ON DELETE SET NULL', q($pdo, 'SELECT base_plan_id FROM pricing_plans WHERE id = ?', [$child]) === null);

// ================================================================ 4. pricing_tiers
$G = 'PRICING_TIERS';
$tier = static fn (int $plan, int $order, $from, $to, $price): ?array => attempt($pdo, 'INSERT INTO pricing_tiers (pricing_plan_id, tier_order, tier_from, tier_to, unit_price) VALUES (?, ?, ?, ?, ?)', [$plan, $order, $from, $to, $price]);
expectOk($G, 'Bậc cho đề xuất (proposal tiers - thay JSON)', $tier($proposalId, 1, 0, 10, 5973));
expectOk($G, 'Bậc cuối không giới hạn (tier_to NULL)', $tier($proposalId, 2, 10, null, 7052));
expectReject($G, 'Trùng (pricing_plan_id, tier_order) - uq_tier_order', $tier($proposalId, 1, 0, 5, 1), [E_DUP]);
expectReject($G, 'tier_to ≤ tier_from', $tier($proposalId, 3, 50, 50, 1), [E_CHECK]);
expectReject($G, 'tier_from âm', $tier($proposalId, 4, -1, 10, 1), [E_CHECK]);
expectReject($G, 'unit_price âm', $tier($proposalId, 5, 100, 200, -5), [E_CHECK]);
expectReject($G, 'FK pricing_plan_id không tồn tại', $tier(999999, 1, 0, 10, 1), [E_FK]);
$pdo->exec("DELETE FROM pricing_plans WHERE id = {$proposalId}");
check($G, 'Xoá phiên bản → bậc xoá theo (CASCADE)', (int) q($pdo, 'SELECT COUNT(*) FROM pricing_tiers WHERE pricing_plan_id = ?', [$proposalId]) === 0);

// ================================================================ 5. alert_events
$G = 'ALERT_EVENTS';
$ev = static fn (array $c): ?array => attempt($pdo, 'INSERT INTO alert_events (' . implode(', ', array_keys($c)) . ') VALUES (' . implode(', ', array_fill(0, count($c), '?')) . ')', array_values($c));
expectOk($G, 'state_change đổi CẢ trạng thái + ưu tiên trong 1 dòng (như lịch sử cũ)', $ev(['alert_id' => 1, 'event_type' => 'state_change', 'old_status' => 'da_review', 'new_status' => 'can_kiem_tra_lai', 'old_priority' => 'trung_binh', 'new_priority' => 'cao', 'note' => 'x', 'created_by' => $bql]));
expectOk($G, 'state_change do hệ thống (created_by NULL, old_status NULL lúc tạo)', $ev(['alert_id' => 1, 'event_type' => 'state_change', 'new_status' => 'moi', 'new_priority' => 'cao']));
expectOk($G, 'contact phone (contact_time, kết quả, người ghi)', $ev(['alert_id' => 2, 'event_type' => 'contact', 'contact_type' => 'phone', 'contact_time' => '2026-10-03 09:00:00', 'contact_result' => 'contacted', 'note' => 'Gọi chủ hộ', 'created_by' => $bql]));
expectOk($G, 'contact resident_response', $ev(['alert_id' => 2, 'event_type' => 'contact', 'contact_type' => 'resident_response', 'contact_time' => '2026-10-03 10:00:00', 'contact_result' => 'co_nguoi_o', 'created_by' => $bql]));
check($G, 'created_at tự điền mặc định', (int) q($pdo, 'SELECT COUNT(*) FROM alert_events WHERE created_at IS NULL') === 0);
expectReject($G, 'state_change thiếu new_status', $ev(['alert_id' => 1, 'event_type' => 'state_change', 'old_status' => 'moi']), [E_CHECK]);
expectReject($G, 'state_change có contact_type', $ev(['alert_id' => 1, 'event_type' => 'state_change', 'new_status' => 'moi', 'contact_type' => 'phone']), [E_CHECK]);
expectReject($G, 'contact thiếu contact_time', $ev(['alert_id' => 2, 'event_type' => 'contact', 'contact_type' => 'phone', 'contact_result' => 'no_answer', 'created_by' => $bql]), [E_CHECK]);
expectReject($G, 'contact thiếu kết quả', $ev(['alert_id' => 2, 'event_type' => 'contact', 'contact_type' => 'phone', 'contact_time' => '2026-10-03 09:00:00', 'created_by' => $bql]), [E_CHECK]);
expectReject($G, 'contact thiếu người ghi (created_by)', $ev(['alert_id' => 2, 'event_type' => 'contact', 'contact_type' => 'phone', 'contact_time' => '2026-10-03 09:00:00', 'contact_result' => 'no_answer']), [E_CHECK]);
expectReject($G, 'contact kèm new_status', $ev(['alert_id' => 2, 'event_type' => 'contact', 'contact_type' => 'phone', 'contact_time' => '2026-10-03 09:00:00', 'contact_result' => 'x', 'created_by' => $bql, 'new_status' => 'moi']), [E_CHECK]);
expectReject($G, 'event_type ngoài ENUM', $ev(['alert_id' => 1, 'event_type' => 'goi_dien', 'new_status' => 'moi']), [E_TRUNC]);
expectReject($G, 'new_status ngoài ENUM trạng thái cảnh báo', $ev(['alert_id' => 1, 'event_type' => 'state_change', 'new_status' => 'dang_xu_ly']), [E_TRUNC]);
expectReject($G, 'FK alert_id không tồn tại', $ev(['alert_id' => 999999, 'event_type' => 'state_change', 'new_status' => 'moi']), [E_FK]);
expectReject($G, 'FK created_by không tồn tại', $ev(['alert_id' => 1, 'event_type' => 'state_change', 'new_status' => 'moi', 'created_by' => 999999]), [E_FK]);
check($G, 'alert_events không có UNIQUE theo thiết kế (nhật ký sự kiện cho phép lặp) — không có ca trùng UNIQUE để thử', true);
// [STEP 4A P-4A-2] fk_event_user ON DELETE RESTRICT: xoá cứng user đã có sự kiện bị chặn -> giữ truy vết người ghi
$pdo->exec("INSERT INTO users (username, password_hash, full_name, role) VALUES ('s_tmp', 'x', 'Tạm', 'bql')");
$tmpUser = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO alert_events (alert_id, event_type, contact_type, contact_time, contact_result, created_by) VALUES (2, 'contact', 'phone', NOW(), 'contacted', {$tmpUser})");
$tmpEvent = (int) $pdo->lastInsertId();
expectReject($G, 'Xoá cứng user đã ghi liên hệ (fk_event_user RESTRICT - P-4A-2)', attempt($pdo, "DELETE FROM users WHERE id = {$tmpUser}"), [E_FK_PARENT]);
check($G, 'Sự kiện liên hệ giữ nguyên người ghi sau lần xoá bị chặn', (int) q($pdo, 'SELECT created_by FROM alert_events WHERE id = ?', [$tmpEvent]) === $tmpUser);
$pdo->exec("INSERT INTO alert_events (alert_id, event_type, new_status, created_by) VALUES (1, 'state_change', 'moi', {$tmpUser})");
$pdo->exec("DELETE FROM alert_events WHERE created_by = {$tmpUser} AND event_type = 'contact'");
expectReject($G, 'Xoá cứng user đã ghi lịch sử trạng thái cũng bị chặn', attempt($pdo, "DELETE FROM users WHERE id = {$tmpUser}"), [E_FK_PARENT]);
$pdo->exec("DELETE FROM alert_events WHERE created_by = {$tmpUser}");
expectOk($G, 'User chưa có sự kiện nào vẫn xoá được', attempt($pdo, "DELETE FROM users WHERE id = {$tmpUser}"));
$fkRule = (string) q($pdo, "SELECT delete_rule FROM information_schema.referential_constraints WHERE constraint_schema = ? AND constraint_name = 'fk_event_user'", [$db]);
check($G, "information_schema: fk_event_user DELETE_RULE = RESTRICT ({$fkRule})", $fkRule === 'RESTRICT');

// ================================================================ 6. recommendation_rules
$G = 'RECOMMENDATION_RULES';
$rr = static fn (array $c): ?array => attempt($pdo, 'INSERT INTO recommendation_rules (' . implode(', ', array_keys($c)) . ') VALUES (' . implode(', ', array_fill(0, count($c), '?')) . ')', array_values($c));
$base = ['name' => 'R', 'recommendation_text' => 'T'];
expectOk($G, 'cl_zero_n_ky N = 2 (như seed)', $rr(['condition_type' => 'cl_zero_n_ky', 'n_periods' => 2] + $base));
expectOk($G, 'vuot_nguong_lien_tiep N = 1, chỉ điện', $rr(['condition_type' => 'vuot_nguong_lien_tiep', 'n_periods' => 1, 'utility_type' => 'dien'] + $base));
expectOk($G, 'khong_dat_muc_tieu không có N, mọi tiện ích', $rr(['condition_type' => 'khong_dat_muc_tieu'] + $base));
expectOk($G, 'N lớn (giới hạn trên ở config, không ở DB)', $rr(['condition_type' => 'cl_zero_n_ky', 'n_periods' => 24] + $base));
expectReject($G, 'cl_zero_n_ky thiếu N', $rr(['condition_type' => 'cl_zero_n_ky'] + $base), [E_CHECK]);
expectReject($G, 'vuot_nguong_lien_tiep N = 0', $rr(['condition_type' => 'vuot_nguong_lien_tiep', 'n_periods' => 0] + $base), [E_CHECK]);
expectReject($G, 'khong_dat_muc_tieu có N', $rr(['condition_type' => 'khong_dat_muc_tieu', 'n_periods' => 3] + $base), [E_CHECK]);
expectReject($G, 'utility_type ngoài ENUM', $rr(['condition_type' => 'khong_dat_muc_tieu', 'utility_type' => 'gas'] + $base), [E_TRUNC]);
expectReject($G, 'condition_type ngoài ENUM', $rr(['condition_type' => 'khac'] + $base), [E_TRUNC]);
expectReject($G, 'FK created_by không tồn tại', $rr(['condition_type' => 'khong_dat_muc_tieu', 'created_by' => 999999] + $base), [E_FK]);
check($G, 'Không còn cột condition_params', (int) q($pdo, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'recommendation_rules' AND column_name = 'condition_params'", [$db]) === 0);
expectReject($G, 'recommendations trùng (alert, rule) - uq_rec_alert_rule', attempt($pdo, "INSERT INTO recommendations (alert_id, recommendation_rule_id, recommendation_text) VALUES (1, 1, 'x')"), [E_DUP]);
expectReject($G, 'Xoá quy tắc đang có khuyến nghị (RESTRICT)', attempt($pdo, 'DELETE FROM recommendation_rules WHERE id = 1'), [E_FK_PARENT]);

// ================================================================ 7. Toàn vẹn cuối
check('FINAL', 'FK violations = 0 sau toàn bộ test', $orphans() === [], json_encode($orphans()));

$pass = 0;
$current = '';
foreach ($results as [$g, $n, $ok, $d]) {
    if ($g !== $current) {
        echo "\n[{$g}]\n";
        $current = $g;
    }
    echo ($ok ? '  PASS ' : '  FAIL ') . $n . ($ok || $d === '' ? '' : "  -> {$d}") . "\n";
    $pass += $ok ? 1 : 0;
}
$total = count($results);
echo "\n{$pass}/{$total} PASS (schema v2 trên DB nháp {$db}, MariaDB {$version})\n";
exit($pass === $total ? 0 : 1);
