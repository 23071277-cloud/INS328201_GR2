<?php

declare(strict_types=1);

/**
 * STEP 14 - Kiểm thử toàn vẹn dữ liệu ở TẦNG DATABASE (không qua Service) + chặn sửa kỳ đã khoá ở tầng Service.
 * Chạy trên DATABASE TẠM:  php tests/core_db_integrity_test.php energy_dss_review_test
 * Mỗi ca chạy trong transaction riêng và ROLLBACK (DB tạm không bị bẩn giữa các ca).
 */

$testDb = $argv[1] ?? '';
if ($testDb === '' || $testDb === 'energy_dss') {
    fwrite(STDERR, "Cần tên database TẠM (khác energy_dss).\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';
$_ENV['DB_DATABASE'] = $testDb;
restore_error_handler();
restore_exception_handler();

use App\Core\Database;
use App\Core\ValidationException;

require_once __DIR__ . '/support/legacy_compat.php'; // STEP 4C: chạy nguyên văn trên schema v1 hoặc v2
$pdo = legacy_compat_install();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

/** SQL phải bị DB từ chối với mã lỗi MySQL mong đợi; luôn ROLLBACK. */
function rejects(string $g, string $n, string $sql, array $codes, string $setup = ''): void
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        if ($setup !== '') {
            $pdo->exec($setup);
        }
        $pdo->exec($sql);
        check($g, $n, false, 'DB chấp nhận câu lệnh');
    } catch (PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        check($g, $n, in_array($code, $codes, true), "mã {$code}: " . $e->getMessage());
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

function accepts(string $g, string $n, string $sql): void
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        check($g, $n, true);
    } catch (PDOException $e) {
        check($g, $n, false, $e->getMessage());
    } finally {
        $pdo->rollBack();
    }
}

const FK = [1451, 1452];
const DUP = [1062];
const BAD_VALUE = [1265, 1366, 1048];
const CHECK = [4025, 3819];

// ================================================================ FK
$G = 'FK';
rejects($G, 'chỉ số trỏ tới công tơ không tồn tại', "INSERT INTO meter_readings (meter_id, billing_period_id, previous_reading, current_reading, consumption, entered_by) VALUES (9999, 2, 0, 1, 1, 2)", FK);
rejects($G, 'xóa công tơ đã có chỉ số (RESTRICT, BR-05)', 'DELETE FROM meters WHERE id = 1', FK);
rejects($G, 'xóa căn hộ có bản tính / công tơ', 'DELETE FROM apartments WHERE id = 1', FK);
rejects($G, 'xóa kỳ đã có chỉ số / bản tính', 'DELETE FROM billing_periods WHERE id = 1', FK);
rejects($G, 'xóa chỉ số được chi tiết bậc tham chiếu (giải trình tiền A4)', 'DELETE FROM meter_readings WHERE id = 1', FK);
rejects($G, 'xóa quy tắc khuyến nghị đã dùng', 'DELETE FROM recommendation_rules WHERE id = 1',
    FK, "INSERT INTO alerts (id, apartment_id, billing_period_id, utility_type, source, status) VALUES (900, 1, 1, 'dien', 'thu_cong', 'moi'); INSERT INTO recommendations (alert_id, recommendation_rule_id, recommendation_text) VALUES (900, 1, 'x')");
rejects($G, 'case kiểm tra giao cho user không tồn tại', "INSERT INTO inspection_cases (alert_id, content, assigned_to, created_by) VALUES (900, 'x', 9999, 1)",
    FK, "INSERT INTO alerts (id, apartment_id, billing_period_id, utility_type, source, status) VALUES (900, 1, 1, 'dien', 'thu_cong', 'moi')");
rejects($G, 'chỉ số gắn lô import không tồn tại (C3)', "UPDATE meter_readings SET import_batch_id = 9999 WHERE id = 1", FK);
$orphans = (int) $pdo->query("SELECT
    (SELECT COUNT(*) FROM meter_readings r LEFT JOIN meters m ON m.id = r.meter_id WHERE m.id IS NULL)
  + (SELECT COUNT(*) FROM apartment_charges c LEFT JOIN apartments a ON a.id = c.apartment_id WHERE a.id IS NULL)
  + (SELECT COUNT(*) FROM charge_tier_details d LEFT JOIN meter_readings r ON r.id = d.meter_reading_id WHERE r.id IS NULL)
  + (SELECT COUNT(*) FROM baselines b LEFT JOIN apartments a ON a.id = b.scope_id WHERE b.scope_type = 'apartment' AND a.id IS NULL)")->fetchColumn();
check($G, 'seed: 0 bản ghi mồ côi (kể cả phạm vi đa hình baselines)', $orphans === 0);

// ================================================================ UNIQUE
$G = 'UNIQUE';
rejects($G, 'trùng chỉ số (công tơ, kỳ) - BR-08', "INSERT INTO meter_readings (meter_id, billing_period_id, previous_reading, current_reading, consumption, entered_by) VALUES (1, 1, 0, 1, 1, 2)", DUP);
rejects($G, 'trùng bản tính (căn, kỳ)', 'INSERT INTO apartment_charges (apartment_id, billing_period_id) VALUES (1, 1)', DUP);
rejects($G, 'trùng mục tiêu (phạm vi, tiện ích, kỳ)', "INSERT INTO consumption_targets (scope_type, scope_id, utility_type, billing_period_id, target_value, created_by) VALUES ('apartment', 1, 'dien', 2, 1, 1)", DUP);
rejects($G, 'trùng cảnh báo quy tắc (quy tắc, căn, công tơ, kỳ) - BR-40', "INSERT INTO alerts (alert_rule_id, meter_id, apartment_id, billing_period_id, utility_type) VALUES (1, 1, 1, 1, 'dien')",
    DUP, "INSERT INTO alerts (alert_rule_id, meter_id, apartment_id, billing_period_id, utility_type) VALUES (1, 1, 1, 1, 'dien')");
rejects($G, 'trùng cảnh báo cấp căn (công tơ NULL) - A3', "INSERT INTO alerts (alert_rule_id, meter_id, apartment_id, billing_period_id, utility_type) VALUES (1, NULL, 1, 1, 'dien')",
    DUP, "INSERT INTO alerts (alert_rule_id, meter_id, apartment_id, billing_period_id, utility_type) VALUES (1, NULL, 1, 1, 'dien')");
accepts($G, 'cảnh báo thủ công (quy tắc NULL) lặp lại được', "INSERT INTO alerts (meter_id, apartment_id, billing_period_id, utility_type, source) VALUES (1, 1, 1, 'dien', 'thu_cong'), (1, 1, 1, 'dien', 'thu_cong')");
rejects($G, 'trùng khuyến nghị (cảnh báo, quy tắc) - patch 002', "INSERT INTO recommendations (alert_id, recommendation_rule_id, recommendation_text) VALUES (900, 1, 'b')",
    DUP, "INSERT INTO alerts (id, apartment_id, billing_period_id, utility_type, source) VALUES (900, 1, 1, 'dien', 'thu_cong'); INSERT INTO recommendations (alert_id, recommendation_rule_id, recommendation_text) VALUES (900, 1, 'a')");
rejects($G, 'trùng mã kỳ', "INSERT INTO billing_periods (period_code, start_date, end_date) VALUES ('06/2024', '2030-01-01', '2030-01-31')", DUP);
rejects($G, 'trùng mã công tơ', "INSERT INTO meters (apartment_id, meter_code, utility_type, usage_type) VALUES (2, 'BT1-DIEN', 'dien', 'sinh_hoat')", DUP);
rejects($G, 'trùng thứ tự bậc trong 1 biểu giá', 'INSERT INTO tariff_tiers (tariff_id, tier_order, tier_from, tier_to, unit_price) VALUES (1, 1, 0, 10, 1)', DUP);
rejects($G, 'trùng username', "INSERT INTO users (username, password_hash, full_name, role) VALUES ('bql01', 'x', 'x', 'bql')", DUP);
// J-01 đã chốt: chỉ 3 vai trò - ENUM users.role không còn 'admin' (patch 003).
rejects('ENUM_STATE', "vai trò 'admin' (chỉ 3 vai trò: bql, ky_thuat, ke_toan)", "INSERT INTO users (username, password_hash, full_name, role) VALUES ('admin_x', 'x', 'x', 'admin')", BAD_VALUE);
rejects($G, 'mục tiêu scope_id NULL (A2)', "INSERT INTO consumption_targets (scope_type, scope_id, utility_type, billing_period_id, target_value, created_by) VALUES ('building', NULL, 'dien', 2, 1, 1)", BAD_VALUE);

// ================================================================ ENUM / trạng thái không hợp lệ
$G = 'ENUM_STATE';
foreach ([
    ['kỳ', "UPDATE billing_periods SET status = 'xoa' WHERE id = 2"],
    ['chỉ số', "UPDATE meter_readings SET status = 'tra_lai' WHERE id = 1"],
    ['bản tính', "UPDATE apartment_charges SET status = 'huy' WHERE id = 1"],
    ['cảnh báo', "UPDATE alerts SET status = 'dang_xu_ly' WHERE id = 900"],
    App\Core\Schema::isV2() // STEP 4C: schema v2 - đề xuất là dòng pricing_plans (approval_status)
        ? ['đề xuất biểu giá', "UPDATE pricing_plans SET approval_status = 'het_han' WHERE origin = 'proposal'"]
        : ['đề xuất biểu giá', "UPDATE tariff_proposals SET status = 'het_han'"],
    ['lô import', "UPDATE import_batches SET status = 'loi'"],
    ['user', "UPDATE users SET status = 'xoa' WHERE id = 1"],
    ['quy tắc cảnh báo (toán tử ngoài schema)', "UPDATE alert_rules SET operator = 'lt' WHERE id = 1"],
    ['quy tắc khuyến nghị', "UPDATE recommendation_rules SET status = 'cho_duyet' WHERE id = 1"],
] as [$label, $sql]) {
    $setup = str_contains($sql, 'alerts') ? "INSERT INTO alerts (id, apartment_id, billing_period_id, utility_type) VALUES (900, 1, 1, 'dien')"
        : (str_contains($sql, "origin = 'proposal'") ? "INSERT INTO pricing_plans (price_type, utility_type, usage_type, vat_percent, effective_date, origin, approval_status, status, base_plan_id, created_by)
            VALUES ('utility', 'dien', 'sinh_hoat', 8, '2030-01-01', 'proposal', 'cho_duyet', NULL, 1, 3)"
        : (str_contains($sql, 'tariff_proposals') ? "INSERT INTO tariff_proposals (tariff_id, utility_type, usage_type, proposed_data, effective_date, proposed_by) VALUES (1, 'dien', 'sinh_hoat', '{}', '2030-01-01', 3)"
        : (str_contains($sql, 'import_batches') ? "INSERT INTO import_batches (billing_period_id, file_name, uploaded_by) VALUES (2, 'x.xlsx', 2)" : '')));
    rejects($G, "giá trị trạng thái ngoài ENUM: {$label}", $sql, BAD_VALUE, $setup);
}

// ================================================================ bậc giá [from, to) - A1
$G = 'TIER';
rejects($G, 'CHECK chk_tier_range: tier_to ≤ tier_from', 'INSERT INTO tariff_tiers (tariff_id, tier_order, tier_from, tier_to, unit_price) VALUES (1, 9, 100, 100, 1)', CHECK);
rejects($G, 'CHECK chk_tier_range: tier_from âm', 'INSERT INTO tariff_tiers (tariff_id, tier_order, tier_from, tier_to, unit_price) VALUES (1, 9, -1, 5, 1)', CHECK);
$gaps = (int) $pdo->query('SELECT COUNT(*) FROM tariff_tiers t LEFT JOIN tariff_tiers p ON p.tariff_id = t.tariff_id AND p.tier_order = t.tier_order - 1
    WHERE (t.tier_order = 1 AND t.tier_from <> 0) OR (t.tier_order > 1 AND (p.id IS NULL OR p.tier_to IS NULL OR t.tier_from <> p.tier_to))')->fetchColumn();
check($G, 'mọi biểu giá: bậc liên tục kiểu [from, to) - bậc k bắt đầu đúng tier_to bậc k−1 (không +1)', $gaps === 0);
$open = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT tariff_id FROM tariff_tiers GROUP BY tariff_id HAVING SUM(tier_to IS NULL) <> 1) x')->fetchColumn();
check($G, 'mỗi biểu giá bậc thang có đúng 1 bậc mở cuối', $open === 0);
$ref = (string) $pdo->query("SELECT SUM(GREATEST(0, LEAST(343, COALESCE(tier_to, 343)) - tier_from) * unit_price) FROM tariff_tiers WHERE tariff_id = 1")->fetchColumn();
check($G, 'công thức SQL COMMENT tariff_tiers cho 343 kWh = 804.350 (nửa khoảng, không +1)', bccomp($ref, '804350', 2) === 0, $ref);
$stored = $pdo->query("SELECT SUM(amount) FROM charge_tier_details WHERE apartment_charge_id = 1 AND utility_type = 'dien'")->fetchColumn();
check($G, 'chi tiết bậc đã lưu BT1 06/2024 = 804.350 = cột electricity_amount', $stored === '804350.00' && $pdo->query('SELECT electricity_amount FROM apartment_charges WHERE id = 1')->fetchColumn() === '804350.00');

// ================================================================ DECIMAL / tổng
$G = 'DECIMAL';
$bad = (int) $pdo->query('SELECT COUNT(*) FROM apartment_charges WHERE total_arising <> electricity_amount + electricity_vat_amount + water_amount + service_fee_amount + fire_insurance_amount + parking_fee_amount
    OR total_due <> total_arising + old_debt_amount')->fetchColumn();
check($G, 'mọi bản tính: tổng = breakdown (BR-18, BR-20)', $bad === 0);
$types = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND (column_name LIKE '%amount%' OR column_name LIKE '%price%' OR column_name IN ('total_arising', 'total_due')) AND column_name <> 'price_type' AND data_type <> 'decimal'")->fetchColumn(); // price_type (v2) = ENUM phân loại, không phải tiền
check($G, 'mọi cột tiền / đơn giá là DECIMAL (không FLOAT/DOUBLE)', (int) $types === 0, (string) $types);
$floats = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND data_type IN ('float', 'double')")->fetchColumn();
check($G, 'không có cột FLOAT/DOUBLE trong schema', $floats === 0);

// ================================================================ kỳ đã khoá - chặn sửa ở Service
$G = 'LOCKED_PERIOD';
$_SESSION = ['user_id' => 2, 'user_role' => 'ky_thuat'];
$_SERVER['REMOTE_ADDR'] = 'core-integrity';
$expect = static function (string $n, callable $fn): void {
    try {
        $fn();
        check('LOCKED_PERIOD', $n, false, 'không bị chặn');
    } catch (ValidationException $e) {
        check('LOCKED_PERIOD', $n, str_contains(mb_strtolower($e->getMessage()), 'khóa'), $e->getMessage());
    }
};
$rs = new App\Services\MeterReadingService();
$expect('sửa chỉ số kỳ 06/2024 (đã khóa)', fn () => $rs->update(1, ['current_reading' => '1400', 'note' => 'x', 'version_token' => App\Services\MeterReadingService::versionToken($rs->find(1))]));
$_SESSION = ['user_id' => 1, 'user_role' => 'bql'];
$expect('duyệt chỉ số kỳ khóa', fn () => (new App\Services\ReadingApprovalService())->approve(1, [1 => 'x']));
$_SESSION = ['user_id' => 3, 'user_role' => 'ke_toan'];
$expect('tính lại phí kỳ khóa', fn () => (new App\Services\BillingCalculationService())->calculateApartment(1, 1));
$expect('sửa nợ cũ kỳ khóa', fn () => (new App\Services\BillingConfirmationService())->updateOldDebt(1, '1', 'x', App\Services\BillingCalculationService::versionToken($pdo->query('SELECT * FROM apartment_charges WHERE id = 1')->fetch())));
$_SESSION = ['user_id' => 1, 'user_role' => 'bql'];
try {
    (new App\Services\TariffService())->create(['utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'vat_percent' => '8', 'business_unit_price' => '', 'effective_date' => '2024-06-15',
        'tiers' => [['tier_from' => '0', 'tier_to' => '', 'unit_price' => '1']]]);
    check($G, 'biểu giá hiệu lực hồi tố vào kỳ khóa (BR-42)', false);
} catch (ValidationException $e) {
    check($G, 'biểu giá hiệu lực hồi tố vào kỳ khóa (BR-42)', isset($e->errors()['effective_date']));
}
check($G, 'dữ liệu kỳ khóa không đổi sau các lần thử', $pdo->query('SELECT CONCAT(current_reading, status) FROM meter_readings WHERE id = 1')->fetchColumn() === '1343.00da_xac_nhan'
    && $pdo->query('SELECT SUM(total_due) FROM apartment_charges WHERE billing_period_id = 1')->fetchColumn() === '4773552.00');

// ================================================================ audit không sửa/xoá được qua ứng dụng
$G = 'AUDIT';
$routes = file_get_contents(BASE_PATH . '/routes/web.php');
// STEP 15: FN-01.06 thêm route TRA CỨU (GET) /audit-logs - mọi route có "audit" phải là GET, không route POST nào chạm log.
preg_match_all('#\$router->(get|post)\(\s*\'([^\']*audit[^\']*)\'#i', $routes, $auditRoutes);
check($G, 'không có route sửa / xóa audit_logs (BR-04): route audit chỉ GET', !preg_match('#\$router->post\(\s*\'[^\']*audit#i', $routes)
    && array_unique($auditRoutes[1]) === ['get'] && $auditRoutes[2] === ['/audit-logs', '/audit-logs/{id}'], json_encode($auditRoutes[2]));
$src = '';
foreach (glob(BASE_PATH . '/app/{Services,Controllers,Repositories}/*.php', GLOB_BRACE) as $f) {
    $src .= file_get_contents($f);
}
check($G, 'không có câu UPDATE / DELETE audit_logs trong mã ứng dụng', !preg_match('/(UPDATE|DELETE FROM)\s+audit_logs/i', $src));

// ===========================================================
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
echo "\n{$pass}/{$total} PASS (db integrity, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
