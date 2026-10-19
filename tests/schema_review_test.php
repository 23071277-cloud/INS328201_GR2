<?php

declare(strict_types=1);

/**
 * Kiểm thử Schema Review (A1-A4, C1-C4, C6) trên một database đã có schema + seed.
 *
 * Chạy:   php tests/schema_review_test.php [db] [host] [port] [user] [pass]
 * Mặc định: energy_dss_test 127.0.0.1 3306 root ""
 *
 * An toàn: mọi INSERT thử nghiệm chạy trong 1 transaction và ROLLBACK ở cuối,
 * không để lại dữ liệu. Không chạy DDL.
 * Thoát mã 0 nếu mọi test PASS, 1 nếu có FAIL.
 */

[$db, $host, $port, $user, $pass] = array_slice(array_pad($argv, 6, null), 1) + [null, null, null, null, null];
$db ??= 'energy_dss_test';
$host ??= '127.0.0.1';
$port ??= '3306';
$user ??= 'root';
$pass ??= '';

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
// STEP 4C: DB schema v2 (26 bảng) -> dùng kết nối tương thích của test (tests/support/legacy_compat.php) để các kiểm tra viết cho
// bảng v1 (tariff_tiers, tariffs…) chạy nguyên văn trên nguồn v2 tương đương; DB v1 giữ nguyên PDO như cũ.
$isV2 = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pricing_plans'")->fetchColumn() > 0;
if ($isV2) {
    putenv("DB_DATABASE={$db}");
    require dirname(__DIR__) . '/bootstrap.php';
    restore_error_handler();
    restore_exception_handler();
    App\Core\Config::set('database.database', $db);
    require_once __DIR__ . '/support/legacy_compat.php';
    $pdo = legacy_compat_install();
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true); // như PDO mặc định của file này
}
$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

$results = [];

function check(string $id, string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$id, $name, $ok, $detail];
}

function scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

/** Chạy câu lệnh, trả về mã lỗi driver (int) hoặc 0 nếu thành công. */
function tryExec(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $pdo->prepare($sql)->execute($params);

        return 0;
    } catch (PDOException $e) {
        return (int) ($e->errorInfo[1] ?? -1);
    }
}

/**
 * Thuật toán tham chiếu tính tiền bậc thang - ĐÚNG quy ước schema (A1):
 * nửa khoảng [tier_from, tier_to), qty = max(0, min(CL, to ?? CL) - from), KHÔNG +1.
 * FN-05.02 (Billing Service) khi code phải cho cùng kết quả với hàm này.
 */
function tierAmount(PDO $pdo, int $tariffId, float $cl): float
{
    $stmt = $pdo->prepare('SELECT tier_from, tier_to, unit_price FROM tariff_tiers WHERE tariff_id = ? ORDER BY tier_order');
    $stmt->execute([$tariffId]);
    $total = 0.0;
    foreach ($stmt as $t) {
        $to = $t['tier_to'] === null ? $cl : (float) $t['tier_to'];
        $qty = max(0.0, min($cl, $to) - (float) $t['tier_from']);
        $total += $qty * (float) $t['unit_price'];
    }

    return round($total, 2);
}

$pdo->beginTransaction();

try {
    // ---------------------------------------------------------------- T01 schema
    $tables = (int) scalar($pdo, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
    $payTable = $isV2 ? (int) scalar($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'payment_transactions'") : 0;
    check('T01', $isV2 ? 'Schema v2: đủ ' . (26 + $payTable) . ' bảng (26 bảng 3NF' . ($payTable ? ' + payment_transactions' : '; payment_transactions chưa migrate') . ')' : 'Schema: đủ 29 bảng (27 Core + email_notifications FN-10.03 patch 004 + alert_contact_logs J-16 patch 005)', $tables === ($isV2 ? 26 + $payTable : 29), "có {$tables}");
    $j16 = (int) scalar($pdo, "SELECT (SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND table_name = 'alerts' AND constraint_name IN ('chk_alert_utility_type', 'chk_alert_snapshot_json'))
        + (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name IN ('uq_alert_system_dedupe', 'uq_email_alert_dedupe'))");
    check('T01', 'Patch 005 (J-16): CHECK utility_type chỉ NULL với no_usage_consumption + snapshot JSON; UNIQUE chống trùng cảnh báo hệ thống + email theo cảnh báo', $j16 === 4, "có {$j16}/4");

    $cols = [
        ['alerts', 'alert_type', 'NO'],
        ['alerts', 'utility_type', 'YES'],
        ['email_notifications', 'alert_id', 'YES'],
        ['meter_readings', 'import_batch_id', 'YES'],
        ['import_batches', 'control_total_details', 'YES'],
        ['charge_tier_details', 'meter_reading_id', 'NO'],
        ['baselines', 'scope_id', 'NO'],
        ['consumption_targets', 'scope_id', 'NO'],
    ];
    foreach ($cols as [$t, $c, $nullable]) {
        $n = scalar($pdo, 'SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
        check('T01', "Cột {$t}.{$c} (nullable={$nullable})", $n === $nullable, 'thực tế: ' . var_export($n, true));
    }
    $gen = (string) scalar($pdo, "SELECT UPPER(extra) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'alerts' AND column_name = 'dedupe_meter_id'");
    check('T01', 'Cột alerts.dedupe_meter_id là cột sinh STORED = COALESCE(meter_id, 0)', str_contains($gen, 'STORED'), $gen);
    $alertsAlerts = (int) scalar($pdo, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'alerts' AND column_name = 'first_viewed_at'");
    check('T01', 'C5: KHÔNG thêm alerts.first_viewed_at (chỉ là [ĐX])', $alertsAlerts === 0);

    // ---------------------------------------------------------------- T02 seed
    // Seed BẮT BUỘC tồn tại (không đếm tổng toàn DB - DB thật có thể có dữ liệu người dùng): 3 tài khoản đúng vai trò, 4 căn seed,
    // kỳ 06/2024 (đã khoá) có 8 chỉ số + 4 bản tính + 14 dòng bậc
    $counts = $pdo->query("SELECT
        (SELECT COUNT(*) FROM users WHERE (username, role) IN (('bql01','bql'),('kythuat01','ky_thuat'),('ketoan01','ke_toan'))) u,
        (SELECT COUNT(*) FROM apartments WHERE (id, code) IN ((1,'BT1'),(2,'S01'),(3,'CC-2305'),(4,'CC-2306'))) a,
        (SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = 1 AND meter_id BETWEEN 1 AND 8) r,
        (SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = 1 AND apartment_id BETWEEN 1 AND 4) c,
        (SELECT COUNT(*) FROM charge_tier_details d JOIN apartment_charges ac ON ac.id = d.apartment_charge_id WHERE ac.billing_period_id = 1 AND ac.apartment_id BETWEEN 1 AND 4) d")->fetch();
    check('T02', 'Seed bắt buộc tồn tại: 3 tài khoản 3 vai trò, 4 căn seed, kỳ 06/2024: 8 chỉ số, 4 bản tính, 14 dòng bậc',
        array_map('intval', $counts) == ['u' => 3, 'a' => 4, 'r' => 8, 'c' => 4, 'd' => 14], json_encode($counts));
    // Kỳ TẠM của test (trong transaction, rollback ở cuối) - thay cho kỳ seed 07/2024 vốn có thể đã có dữ liệu thật
    $pdo->exec("INSERT INTO billing_periods (period_code, start_date, end_date, status, created_by) VALUES ('12/2099', '2099-12-01', '2099-12-31', 'mo', 1)");
    $tp = (int) $pdo->lastInsertId();

    // ---------------------------------------------------------------- T03 FK
    $fks = $pdo->query("SELECT k.table_name AS table_name, k.column_name AS column_name, k.referenced_table_name AS referenced_table_name,
          k.referenced_column_name AS referenced_column_name, k.constraint_name AS constraint_name
        FROM information_schema.key_column_usage k
        WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL")->fetchAll();
    $orphanTotal = 0;
    $orphanWhere = [];
    foreach ($fks as $fk) {
        $n = (int) scalar($pdo, sprintf(
            'SELECT COUNT(*) FROM `%s` c LEFT JOIN `%s` p ON p.`%s` = c.`%s` WHERE c.`%s` IS NOT NULL AND p.`%s` IS NULL',
            $fk['table_name'], $fk['referenced_table_name'], $fk['referenced_column_name'],
            $fk['column_name'], $fk['column_name'], $fk['referenced_column_name']
        ));
        if ($n > 0) {
            $orphanWhere[] = "{$fk['constraint_name']}={$n}";
        }
        $orphanTotal += $n;
    }
    check('T03', 'FK: 0 bản ghi mồ côi trên ' . count($fks) . ' khoá ngoại', $orphanTotal === 0, implode(', ', $orphanWhere));

    $rules = $pdo->query("SELECT constraint_name, delete_rule FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE() AND constraint_name IN ('fk_alert_meter','fk_tierdetail_reading','fk_reading_import_batch')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    check('T03', 'FK mới: fk_tierdetail_reading, fk_reading_import_batch = RESTRICT',
        ($rules['fk_tierdetail_reading'] ?? '') === 'RESTRICT' && ($rules['fk_reading_import_batch'] ?? '') === 'RESTRICT', json_encode($rules));
    check('T03', 'A3: fk_alert_meter = RESTRICT (thay SET NULL)', ($rules['fk_alert_meter'] ?? '') === 'RESTRICT');

    // ---------------------------------------------------------------- T04 UNIQUE / A2
    $insTarget = 'INSERT INTO consumption_targets (scope_type, scope_id, utility_type, billing_period_id, target_value, created_by) VALUES (?, ?, ?, ?, ?, 1)';
    check('T04', 'A2: mục tiêu toàn tòa (building_id=1) lần 1 -> OK', tryExec($pdo, $insTarget, ['building', 1, 'dien', $tp, 50000]) === 0);
    $e = tryExec($pdo, $insTarget, ['building', 1, 'dien', $tp, 60000]);
    check('T04', 'A2: mục tiêu toàn tòa trùng (cùng tòa, tiện ích, kỳ) -> 1062', $e === 1062, "mã lỗi {$e}");
    $e = tryExec($pdo, $insTarget, ['building', null, 'dien', $tp, 50000]);
    check('T04', 'A2: consumption_targets.scope_id NULL -> bị từ chối (1048)', $e === 1048, "mã lỗi {$e}");
    $e = tryExec($pdo, "INSERT INTO baselines (scope_type, scope_id, utility_type, method, effective_date) VALUES ('building', NULL, 'dien', 'ky_truoc', '2024-07-01')");
    check('T04', 'A2: baselines.scope_id NULL -> bị từ chối (1048)', $e === 1048, "mã lỗi {$e}");
    check('T04', 'A2: mục tiêu cùng tòa nhưng tiện ích khác -> OK', tryExec($pdo, $insTarget, ['building', 1, 'nuoc', $tp, 900]) === 0);

    // ---------------------------------------------------------------- T05 alert dedupe / A3
    $insAlert = 'INSERT INTO alerts (alert_rule_id, meter_id, apartment_id, billing_period_id, utility_type, source, description) VALUES (?, ?, ?, ?, ?, ?, ?)';
    check('T05', 'A3: rule 1 + công tơ 1 + kỳ tạm lần 1 -> OK', tryExec($pdo, $insAlert, [1, 1, 1, $tp, 'dien', 'rule', null]) === 0);
    $e = tryExec($pdo, $insAlert, [1, 1, 1, $tp, 'dien', 'rule', null]);
    check('T05', 'A3: cùng rule + công tơ + kỳ -> 1062', $e === 1062, "mã lỗi {$e}");
    check('T05', 'A3: cảnh báo chi phí cấp căn (meter NULL) lần 1 -> OK', tryExec($pdo, $insAlert, [1, null, 1, $tp, 'dien', 'rule', null]) === 0);
    $e = tryExec($pdo, $insAlert, [1, null, 1, $tp, 'dien', 'rule', null]);
    check('T05', 'A3: cùng rule + căn (meter NULL) + kỳ -> 1062', $e === 1062, "mã lỗi {$e}");
    check('T05', 'A3: cùng rule + kỳ nhưng công tơ khác (meter 5) -> OK', tryExec($pdo, $insAlert, [1, 5, 3, $tp, 'dien', 'rule', null]) === 0);
    $m1 = tryExec($pdo, $insAlert, [null, 1, 1, $tp, 'dien', 'thu_cong', 'Công tơ quay bất thường']);
    $m2 = tryExec($pdo, $insAlert, [null, 1, 1, $tp, 'dien', 'thu_cong', 'Bổ sung: rò rỉ nhìn thấy']);
    check('T05', 'A3: 2 cảnh báo thủ công cùng công tơ/kỳ -> không bị chặn', $m1 === 0 && $m2 === 0, "mã lỗi {$m1}/{$m2}");

    // ---------------------------------------------------------------- T06 tariff tier / A1
    $gap = (int) scalar($pdo, 'SELECT COUNT(*) FROM tariff_tiers t LEFT JOIN tariff_tiers p ON p.tariff_id = t.tariff_id AND p.tier_order = t.tier_order - 1
        WHERE (t.tier_order = 1 AND t.tier_from <> 0) OR (t.tier_order > 1 AND (p.id IS NULL OR p.tier_to IS NULL OR p.tier_to <> t.tier_from))');
    check('T06', 'A1: mọi biểu giá liên tục [from, to), bậc 1 từ 0, không hở/chồng lấn', $gap === 0, "{$gap} bậc lỗi");
    $open = (int) scalar($pdo, 'SELECT COUNT(*) FROM (SELECT tariff_id FROM tariff_tiers GROUP BY tariff_id
        HAVING SUM(tier_to IS NULL) <> 1 OR MAX(tier_order) <> MAX(CASE WHEN tier_to IS NULL THEN tier_order END)) x');
    check('T06', 'A1: mỗi biểu giá có đúng 1 bậc mở (tier_to NULL) và là bậc cuối', $open === 0);
    $e = tryExec($pdo, 'INSERT INTO tariff_tiers (tariff_id, tier_order, tier_from, tier_to, unit_price) VALUES (2, 9, 100, 100, 1)');
    check('T06', 'A1: CHECK chặn tier_to <= tier_from', in_array($e, [4025, 3819], true), "mã lỗi {$e}");

    $cases = [
        // [mô tả, tariff, CL, kỳ vọng trước VAT]
        ['Ví dụ tài liệu: điện 343 kWh', 1, 343, 804350.00],
        ['Ví dụ tài liệu: nước 16 m3', 3, 16, 185120.00],
        ['Biên: 0 kWh', 1, 0, 0.00],
        ['Biên: 50 kWh (hết bậc 1)', 1, 50, 90300.00],
        ['Biên: 50.5 kWh (thập phân trong khe cũ 50-51)', 1, 50.5, 91233.00],
        ['Biên: 100 kWh', 1, 100, 183600.00],
        ['Biên: 400 kWh (hết bậc 5)', 1, 400, 978200.00],
        ['Biên: 401 kWh (vào bậc mở)', 1, 401, 981351.00],
        ['Biên: nước 10 m3', 3, 10, 110450.00],
        ['Biên: nước 30.5 m3', 3, 30.5, 110450 + 124450 + 157450 + 0.5 * 17245],
    ];
    foreach ($cases as [$label, $tariff, $cl, $expected]) {
        $got = tierAmount($pdo, $tariff, (float) $cl);
        check('T06', "Tính tiền - {$label} = " . number_format($expected, 0, ',', '.'), abs($got - $expected) < 0.005, "thuật toán ra {$got}");
    }
    $amount343 = tierAmount($pdo, 1, 343);
    $vat = round($amount343 * 8 / 100);
    check('T06', 'Ví dụ tài liệu: VAT 8% = 64.348, tổng điện = 868.698', $vat == 64348 && $amount343 + $vat == 868698, "VAT {$vat}, tổng " . ($amount343 + $vat));
    $sqlAmount = (float) scalar($pdo, 'SELECT SUM(GREATEST(0, LEAST(343, COALESCE(tier_to, 343)) - tier_from) * unit_price) FROM tariff_tiers WHERE tariff_id = 1');
    check('T06', 'Công thức SQL trong COMMENT schema cho cùng kết quả 804.350', abs($sqlAmount - 804350) < 0.005, "SQL ra {$sqlAmount}");

    // ---------------------------------------------------------------- T07 import traceability / C3 + C4
    $pdo->exec("INSERT INTO import_batches (billing_period_id, file_name, total_rows, valid_rows, error_rows, control_total_diff, control_total_details, status, uploaded_by)
        VALUES ({$tp}, 'chi_so_T07_2024.xlsx', 1, 1, 0, 2.00, JSON_OBJECT(
          'electricity_consumption', JSON_OBJECT('label', 'CL điện', 'expected', 341, 'actual', 343, 'difference', 2),
          'water_consumption',       JSON_OBJECT('label', 'CL nước', 'expected', 16, 'actual', 16, 'difference', 0),
          'grand_total',             JSON_OBJECT('label', 'Tổng cộng', 'expected', 1950058, 'actual', 1950058, 'difference', 0)), 'da_luu', 2)");
    $batchId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO meter_readings (meter_id, billing_period_id, previous_reading, current_reading, consumption, source, import_batch_id, entered_by)
        VALUES (1, ?, 1343, 1686, 343, 'import', ?, 2)")->execute([$tp, $batchId]);
    $readingId = (int) $pdo->lastInsertId();
    $file = scalar($pdo, 'SELECT b.file_name FROM meter_readings r JOIN import_batches b ON b.id = r.import_batch_id WHERE r.id = ?', [$readingId]);
    check('T07', 'C3: chỉ số import truy ngược được về lô (file_name)', $file === 'chi_so_T07_2024.xlsx', (string) $file);
    $manualNull = (int) scalar($pdo, "SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = 1 AND source = 'thu_cong' AND import_batch_id IS NULL");
    $manualWithBatch = (int) scalar($pdo, "SELECT COUNT(*) FROM meter_readings WHERE source = 'thu_cong' AND import_batch_id IS NOT NULL");
    check('T07', 'C3: chỉ số nhập tay có import_batch_id NULL (8 bản ghi seed kỳ 06/2024; không bản nhập tay nào gắn lô)', $manualNull === 8 && $manualWithBatch === 0, "{$manualNull} / {$manualWithBatch}");
    $e = tryExec($pdo, "INSERT INTO meter_readings (meter_id, billing_period_id, previous_reading, current_reading, consumption, source, import_batch_id, entered_by) VALUES (2, {$tp}, 216, 230, 14, 'import', 999999, 2)");
    check('T07', 'C3: import_batch_id không tồn tại -> FK chặn (1452)', $e === 1452, "mã lỗi {$e}");
    $diff = scalar($pdo, "SELECT JSON_EXTRACT(control_total_details, '$.electricity_consumption.difference') FROM import_batches WHERE id = ?", [$batchId]);
    $keys = scalar($pdo, 'SELECT JSON_LENGTH(control_total_details) FROM import_batches WHERE id = ?', [$batchId]);
    check('T07', 'C4: control_total_details lưu và đọc được chênh lệch theo từng chỉ tiêu', (float) $diff === 2.0 && (int) $keys === 3, "difference={$diff}, số chỉ tiêu={$keys}");

    // ---------------------------------------------------------------- T08 A4 traceability + charge integrity
    $bad = $pdo->query("SELECT d.id FROM charge_tier_details d
        JOIN apartment_charges c ON c.id = d.apartment_charge_id
        JOIN meter_readings r ON r.id = d.meter_reading_id
        JOIN meters m ON m.id = r.meter_id
        WHERE m.apartment_id <> c.apartment_id OR r.billing_period_id <> c.billing_period_id OR m.utility_type <> d.utility_type")->fetchAll();
    check('T08', 'A4: mọi dòng bậc trỏ đúng chỉ số cùng căn, cùng kỳ, cùng tiện ích', $bad === [], count($bad) . ' dòng lệch');

    $perMeter = $pdo->query("SELECT r.id, r.billing_period_id, r.consumption, m.usage_type, m.utility_type, SUM(d.quantity) qty, SUM(d.amount) amt
        FROM charge_tier_details d JOIN meter_readings r ON r.id = d.meter_reading_id JOIN meters m ON m.id = r.meter_id
        GROUP BY r.id, r.billing_period_id, r.consumption, m.usage_type, m.utility_type")->fetchAll();
    $okMeter = true;
    $why = [];
    foreach ($perMeter as $row) {
        $tariff = (int) scalar($pdo, "SELECT id FROM tariffs WHERE utility_type = ? AND usage_type = ? AND status = 'hieu_luc'", [$row['utility_type'], $row['usage_type']]);
        $expect = $row['usage_type'] === 'kinh_doanh'
            ? (float) $row['consumption'] * (float) scalar($pdo, 'SELECT business_unit_price FROM tariffs WHERE id = ?', [$tariff])
            : tierAmount($pdo, $tariff, (float) $row['consumption']);
        if (abs((float) $row['qty'] - (float) $row['consumption']) > 0.005 || abs((float) $row['amt'] - $expect) > 0.005) {
            $okMeter = false;
            $why[] = "reading {$row['id']}";
        }
    }
    $seedMeters = count(array_filter($perMeter, static fn (array $r): bool => (int) $r['billing_period_id'] === 1));
    check('T08', 'A4: theo từng công tơ (mọi kỳ), tổng qty = CL và tổng tiền = thuật toán tham chiếu; kỳ seed 06/2024 có 6 công tơ có tiền', $okMeter && $seedMeters === 6, implode(',', $why) . ' / ' . $seedMeters . ' công tơ seed');

    $charges = $pdo->query("SELECT c.*, t.vat_percent,
          (SELECT COALESCE(SUM(d.amount),0) FROM charge_tier_details d WHERE d.apartment_charge_id = c.id AND d.utility_type = 'dien') dien_amt,
          (SELECT COALESCE(SUM(d.amount),0) FROM charge_tier_details d WHERE d.apartment_charge_id = c.id AND d.utility_type = 'nuoc') nuoc_amt,
          (SELECT COALESCE(SUM(r.consumption),0) FROM meter_readings r JOIN meters m ON m.id = r.meter_id WHERE m.apartment_id = c.apartment_id AND r.billing_period_id = c.billing_period_id AND m.utility_type = 'dien') dien_cl,
          (SELECT COALESCE(SUM(r.consumption),0) FROM meter_readings r JOIN meters m ON m.id = r.meter_id WHERE m.apartment_id = c.apartment_id AND r.billing_period_id = c.billing_period_id AND m.utility_type = 'nuoc') nuoc_cl
        FROM apartment_charges c JOIN tariffs t ON t.id = 1")->fetchAll();
    $bad = [];
    foreach ($charges as $c) {
        $sum = $c['electricity_amount'] + $c['electricity_vat_amount'] + $c['water_amount'] + $c['service_fee_amount'] + $c['fire_insurance_amount'] + $c['parking_fee_amount'];
        $okRow = abs($c['electricity_amount'] - $c['dien_amt']) < 0.005
            && abs($c['water_amount'] - $c['nuoc_amt']) < 0.005
            // J-15 [ASSUMPTION]: hệ thống làm tròn tiền tới XU (2 chữ số) - vd 307.119 × 8% = 24.569,52
            && abs($c['electricity_vat_amount'] - round($c['electricity_amount'] * $c['vat_percent'] / 100, 2)) < 0.005
            && abs($c['total_arising'] - $sum) < 0.005
            && abs($c['total_due'] - ($c['total_arising'] + $c['old_debt_amount'])) < 0.005
            && abs($c['electricity_consumption'] - $c['dien_cl']) < 0.005
            && abs($c['water_consumption'] - $c['nuoc_cl']) < 0.005;
        if (!$okRow) {
            $bad[] = $c['id'];
        }
    }
    check('T08', 'Seed: bản tính khớp chi tiết bậc, VAT 8%, tổng phát sinh, tổng phải thu (BR-18)', $bad === [], 'charge lệch: ' . implode(',', $bad));
    $bt1 = scalar($pdo, 'SELECT total_due FROM apartment_charges WHERE id = 1');
    check('T08', 'Seed: phiếu BT1 06/2024 tổng phải thu = 1.950.058 (không đổi so với trước)', (float) $bt1 === 1950058.0, (string) $bt1);

    // ---------------------------------------------------------------- T09 seed consistency / C6
    $draftInLocked = (int) scalar($pdo, "SELECT COUNT(*) FROM meter_readings r JOIN billing_periods p ON p.id = r.billing_period_id WHERE p.status = 'da_khoa' AND r.status <> 'da_xac_nhan'");
    check('T09', 'C6: kỳ đã khoá không còn chỉ số Nháp', $draftInLocked === 0);
    $missing = (int) scalar($pdo, "SELECT COUNT(*) FROM billing_periods p JOIN apartments a ON a.status = 'hoat_dong'
        LEFT JOIN apartment_charges c ON c.apartment_id = a.id AND c.billing_period_id = p.id AND c.status IN ('da_xac_nhan','da_duyet','da_phat_hanh')
        WHERE p.status = 'da_khoa' AND a.created_at <= p.locked_at AND c.id IS NULL");
    check('T09', 'C6: kỳ đã khoá -> mọi căn hoạt động (đã có lúc khoá kỳ) có bản tính đã xác nhận/duyệt/phát hành', $missing === 0, "{$missing} căn thiếu");
    $issuedAfterLock = (int) scalar($pdo, "SELECT COUNT(*) FROM apartment_charges c JOIN billing_periods p ON p.id = c.billing_period_id WHERE p.status = 'da_khoa' AND c.issued_at IS NOT NULL AND c.issued_at > p.locked_at");
    // ứng dụng chưa có bước "Phát hành" (FN-09.02 PARTIAL) nên kỳ khoá qua ứng dụng có issued_at NULL - chỉ kiểm phiếu ĐÃ phát hành
    check('T09', 'C6: phiếu đã phát hành (issued_at có giá trị) đều trước thời điểm khoá kỳ (BR-23)', $issuedAfterLock === 0);
    $badBaseline = $pdo->query("SELECT b.id FROM baselines b WHERE
          (b.method = 'ky_truoc' AND (b.n_periods IS NOT NULL OR b.computed_value <> (
              SELECT COALESCE(SUM(r.consumption),0) FROM meter_readings r JOIN meters m ON m.id = r.meter_id
              JOIN billing_periods p ON p.id = r.billing_period_id
              WHERE m.apartment_id = b.scope_id AND m.utility_type = b.utility_type AND p.status = 'da_khoa'
                AND p.end_date = (SELECT MAX(p2.end_date) FROM billing_periods p2 WHERE p2.status = 'da_khoa' AND p2.end_date < b.effective_date))))
          OR (b.method = 'trung_binh_n_ky' AND b.n_periods > (SELECT COUNT(*) FROM billing_periods p WHERE p.status = 'da_khoa' AND p.end_date < b.effective_date))")->fetchAll();
    check('T09', 'C6: baseline khớp dữ liệu thật (ky_truoc = CL kỳ khoá liền trước; không có n_periods giả)', $badBaseline === [], json_encode($badBaseline));

    // ---------------------------------------------------------------- T10 C1 / C2
    $pct = (int) scalar($pdo, "SELECT COUNT(*) FROM alert_rules WHERE name LIKE '%\\%\\%%'");
    $pctCmt = (int) scalar($pdo, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_comment LIKE '%\\%\\%%'");
    check('T10', 'C1: không còn "%%" trong alert_rules.name và COMMENT cột', $pct === 0 && $pctCmt === 0, "name={$pct}, comment={$pctCmt}");
    $br40 = scalar($pdo, "SELECT index_comment FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'alerts' AND index_name = 'uq_alert_dedupe' LIMIT 1");
    check('T10', 'C2: uq_alert_dedupe ghi nhãn [DX] BR-40', is_string($br40) && str_starts_with($br40, '[DX] BR-40'), (string) $br40);
} finally {
    $pdo->rollBack();
}

$failed = 0;
foreach ($results as [$id, $name, $ok, $detail]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-4s %s%s\n", $ok ? 'PASS' : 'FAIL', $id, $name, $ok || $detail === '' ? '' : "  -> {$detail}");
}
printf("\n%d/%d PASS (db=%s)\n", count($results) - $failed, count($results), $db);
exit($failed === 0 ? 0 : 1);
