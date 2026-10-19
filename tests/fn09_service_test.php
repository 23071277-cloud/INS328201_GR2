<?php

declare(strict_types=1);

/**
 * STEP 13 - Test cấp Service FN-09 (dashboard, báo cáo tiện ích, CSV, phiếu thông báo, read-only).
 * Chạy trên DATABASE TẠM:  php tests/fn09_service_test.php energy_dss_fn09_test
 * Seed: kỳ 06/2024 khoá (4 bản tính phát hành), 07/2024 mở; fixture: chỉ số 07, công tơ KD thứ 2 cho CC-2305,
 * bản tính 07 (xác nhận / CẦN TÍNH LẠI / nợ cũ), cảnh báo + khuyến nghị, mục tiêu.
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
use App\Services\AlertEvaluationService;
use App\Services\AlertRuleService;
use App\Services\AlertService;
use App\Services\AlertWorkflowService;
use App\Services\BillingCalculationService;
use App\Services\BillingConfirmationService;
use App\Services\BillingPeriodService;
use App\Services\BillNoticeService;
use App\Services\ConsumptionTargetService;
use App\Services\DashboardService;
use App\Services\MeterReadingService;
use App\Services\MeterService;
use App\Services\ParkingVehicleService;
use App\Services\ReadingApprovalService;
use App\Services\RecommendationService;
use App\Services\UtilityReportService;
use App\Support\CsvWriter;

$pdo = Database::connection();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
$_SERVER['REMOTE_ADDR'] = 'fn09-service-test';
const BQL = 1;
const KT = 2;
const KTO = 3;
const JUN = 1;
const JUL = 2;

function actAs(int $id, string $role): void
{
    $_SESSION = ['user_id' => $id, 'user_role' => $role, 'username' => $role, 'full_name' => $role];
}

function user(int $id, string $role): array
{
    return ['id' => $id, 'role' => $role];
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

/** Ảnh chụp dữ liệu nghiệp vụ để chứng minh read-only. */
function snapshot(PDO $pdo): string
{
    $parts = [];
    foreach (['apartment_charges' => 'id, status, total_due, updated_at, issued_at', 'charge_tier_details' => 'id, amount', 'meter_readings' => 'id, status, current_reading, updated_at',
        'alerts' => 'id, status, priority, updated_at', 'recommendations' => 'id', 'inspection_cases' => 'id, status', 'billing_periods' => 'id, status', 'audit_logs' => 'COUNT(*)'] as $t => $cols) {
        $parts[] = json_encode($pdo->query("SELECT {$cols} FROM {$t}" . (str_contains($cols, 'COUNT') ? '' : ' ORDER BY 1'))->fetchAll());
    }

    return sha1(implode('|', $parts));
}

$dash = new DashboardService();
$report = new UtilityReportService();
$f = static fn (array $o = []) => array_merge(['period_id' => '', 'building_id' => '', 'zone_id' => '', 'utility_type' => ''], $o);
$rf = static fn (array $o = []) => array_merge(['period_id' => '', 'building_id' => '', 'zone_id' => '', 'apartment' => '', 'utility_type' => ''], $o);

// =========================================================== NO DATA (trước fixture kỳ 08)
$G = 'DASHBOARD';
actAs(BQL, 'bql');
$aug = (new BillingPeriodService())->create(['month' => '8', 'year' => '2024'])['id'];
$d = $dash->build($f(['period_id' => (string) $aug]), user(BQL, 'bql'));
check($G, 'kỳ chưa có dữ liệu (08/2024): không lỗi, has_data = false, tiêu thụ null, biểu đồ không có cột', $d['has_data'] === false && $d['consumption']['dien']['current_value'] === null
    && array_key_exists('08/2024', $d['trend']['dien']) && $d['trend']['dien']['08/2024'] === null);
check($G, '… biểu đồ SVG: không có điểm / toàn kỳ thiếu dữ liệu → "Chưa có dữ liệu cho kỳ đã chọn."; kỳ thiếu xen kẽ → "chưa đủ" (không vẽ cột 0)', str_contains(svg_bars([], 'kWh', 't'), 'Chưa có dữ liệu cho kỳ đã chọn.')
    && str_contains(svg_bars(['08/2024' => null], 'kWh', 't'), 'Chưa có dữ liệu cho kỳ đã chọn.') && str_contains(svg_bars(['07/2024' => 5.0, '08/2024' => null], 'kWh', 't'), 'chưa đủ'));
$d = $dash->build($f(), user(BQL, 'bql'));
check($G, 'không chọn kỳ → kỳ gần nhất có chỉ số Đã xác nhận (06/2024), ghi rõ', ($d['period']['period_code'] ?? '') === '06/2024' && $d['defaulted'] === true);
check($G, 'một kỳ (06/2024 đã khóa): điện 643 kWh, nước 58 m³ — tách riêng, không cộng', $d['consumption']['dien']['current_value'] === '643.00' && $d['consumption']['nuoc']['current_value'] === '58.00'
    && !isset($d['consumption']['tong']));
check($G, 'chi phí chính thức (kỳ khóa) = Σ bản tính FN-05: điện gồm VAT 1.816.452 + nước 1.075.260; tổng phát sinh 4.773.552', $d['cost']['official'] === true
    && $d['cost']['electricity_cost'] === '1816452.00' && $d['cost']['water_cost'] === '1075260.00' && $d['cost']['total_arising'] === '4773552.00');
$d = $dash->build($f(['period_id' => (string) JUN, 'zone_id' => '3']), user(BQL, 'bql'));
check($G, 'lọc khu KHU-CC: điện 150, nước 12; chi phí chỉ của 2 căn khu', $d['consumption']['dien']['current_value'] === '150.00' && $d['consumption']['nuoc']['current_value'] === '12.00'
    && $d['cost']['total_arising'] === '1039066.00' && (int) $d['cost']['charges'] === 2);
$d = $dash->build($f(['period_id' => 'abc', 'building_id' => '999', 'zone_id' => '999', 'utility_type' => 'gas']), user(BQL, 'bql'));
check($G, 'filter không hợp lệ (kỳ "abc", tòa 999, khu 999, tiện ích "gas") → mặc định an toàn, không lỗi', $d['filters']['building_id'] === '1' && $d['filters']['zone_id'] === '' && $d['filters']['utility_type'] === 'dien');

// =========================================================== FIXTURE kỳ 07/2024
$readings = new MeterReadingService();
$approval = new ReadingApprovalService();
actAs(BQL, 'bql');
$m9 = (int) (new MeterService())->create(['apartment_id' => '3', 'meter_code' => 'CC2305-KD', 'utility_type' => 'dien', 'usage_type' => 'kinh_doanh', 'display_label' => 'KD', 'initial_reading' => '1000']);
foreach ([1 => '1800', 2 => '232', 3 => '800', 4 => '160', 5 => '600', 6 => '74', 7 => '0', 8 => '0', $m9 => '1100'] as $meter => $csc) {
    actAs(KT, 'ky_thuat');
    $r = $readings->create(JUL, $meter, ['current_reading' => $csc, 'note' => '']);
    actAs(BQL, 'bql');
    $approval->approve(JUL, [(int) $r['id'] => MeterReadingService::versionToken($readings->find((int) $r['id']))]);
}
actAs(KTO, 'ke_toan');
$calc = new BillingCalculationService();
$calc->calculatePeriod(JUL);
$conf = new BillingConfirmationService();
$tok = static fn (int $apt) => BillingCalculationService::versionToken($pdo->query("SELECT * FROM apartment_charges WHERE apartment_id = {$apt} AND billing_period_id = " . JUL)->fetch());
$cid = static fn (int $apt) => (int) q($pdo, 'SELECT id FROM apartment_charges WHERE apartment_id = ? AND billing_period_id = ?', [$apt, JUL]);
$conf->updateOldDebt($cid(4), '50000', 'Nợ T6 chưa thanh toán', $tok(4));
$conf->confirm(JUL, [$cid(1) => $tok(1), $cid(2) => $tok(2), $cid(4) => $tok(4)]); // CC-2305 (căn 3) để Đã tính
actAs(BQL, 'bql');
(new ParkingVehicleService())->update(2, ['quantity_xe_dap_dien' => '0', 'quantity_xe_may' => '3', 'quantity_o_to' => '0']); // S01 -> CẦN TÍNH LẠI
$absRule = (new AlertRuleService())->create(['name' => 'Điện > 90', 'measure_type' => 'tieu_thu', 'utility_type' => 'dien', 'compare_base' => 'gia_tri_tuyet_doi', 'operator' => 'gt',
    'threshold_value' => '90', 'scope_type' => 'toan_toa', 'scope_value' => '', 'priority_level' => 'cao']);
(new AlertEvaluationService())->evaluatePeriod(JUL, $absRule); // BT1 457, S01 150, CC2305 SH 150, CC2305 KD 100 -> 4 cảnh báo cao
$alertIds = array_map('intval', $pdo->query("SELECT id FROM alerts ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$wf = new AlertWorkflowService();
$a = $alertIds[3];
$wf->review($a, 'thap', '', AlertService::versionToken($pdo->query("SELECT * FROM alerts WHERE id = {$a}")->fetch()));
$wf->close($a, 'Đã xem', AlertService::versionToken($pdo->query("SELECT * FROM alerts WHERE id = {$a}")->fetch()));
(new RecommendationService())->generateForAlert($alertIds[0]); // BT1: quy tắc #2 (vượt ngưỡng 1 kỳ)
(new ConsumptionTargetService())->create(['scope_type' => 'apartment', 'scope_id' => '3', 'utility_type' => 'dien', 'billing_period_id' => (string) JUL, 'target_value' => '300']);
actAs(KT, 'ky_thuat');
(new AlertService())->createManual(['apartment_id' => '4', 'meter_id' => '8', 'billing_period_id' => (string) JUL, 'utility_type' => 'nuoc', 'description' => 'Công tơ không quay', 'priority' => 'trung_binh']);
actAs(BQL, 'bql');

// =========================================================== DASHBOARD kỳ 07 (nhiều kỳ, cảnh báo, mục tiêu, khuyến nghị)
$before = snapshot($pdo);
$d = $dash->build($f(['period_id' => (string) JUL]), user(BQL, 'bql'));
check($G, 'nhiều kỳ: xu hướng điện 06/2024 = 643, 07/2024 = 857; nước 58 / 58 (2 chuỗi riêng)', ($d['trend']['dien']['06/2024'] ?? null) == 643 && ($d['trend']['dien']['07/2024'] ?? null) == 857
    && ($d['trend']['nuoc']['07/2024'] ?? null) == 58, json_encode($d['trend']));
check($G, 'so kỳ trước (FN-06): điện +33,28% (643 → 857)', $d['consumption']['dien']['previous_percentage_change'] === '33.28', (string) $d['consumption']['dien']['previous_percentage_change']);
check($G, 'cảnh báo đang mở 4 (3 quy tắc + 1 thủ công), ưu tiên cao 3, đã đóng 1 không tính mở', $d['alerts']['open'] === 4 && $d['alerts']['high'] === 3 && ($d['alerts']['by']['status']['da_dong'] ?? 0) === 1);
check($G, 'top cảnh báo cần xử lý: sắp ưu tiên cao trước, không có cảnh báo đã đóng', count($d['alerts']['top']) === 4 && $d['alerts']['top'][0]['priority'] === 'cao'
    && !in_array('da_dong', array_column($d['alerts']['top'], 'status'), true));
check($G, 'cảnh báo theo tiện ích (đang mở): điện 3, nước 1', ($d['alerts']['by']['utility']['dien'] ?? 0) === 3 && ($d['alerts']['by']['utility']['nuoc'] ?? 0) === 1);
check($G, 'mục tiêu: BT1 320 → Không đạt (457), CC-2305 300 → Đạt (250); tỷ lệ đạt 50%', $d['targets']['dat'] === 1 && $d['targets']['khong_dat'] === 1 && $d['targets']['rate'] === '50.00');
check($G, 'khuyến nghị (FN-08 đã sinh, không sinh khi tải): 1 cảnh báo có khuyến nghị', $d['recommendations']['alerts'] === 1 && $d['recommendations']['total'] >= 1);
check($G, 'baseline (FN-06, BQL): BT1 điện 457 > baseline 343 → "cao hơn baseline" 1 căn', $d['baseline']['above'] === 1 && $d['baseline']['top_increase'][0]['scope_id'] === 1);
check($G, 'chi phí kỳ mở: "tạm" (không chính thức), chỉ cộng bản Đã xác nhận+', $d['cost']['official'] === false && (int) $d['cost']['confirmed'] === 3);
check($G, 'top căn tiêu thụ điện: BT1 457 đứng đầu', ($d['top_consumers'][0]['apartment']['code'] ?? '') === 'BT1' && $d['top_consumers'][0]['value'] === 45700);
actAs(KT, 'ky_thuat');
$k = $dash->build($f(['period_id' => (string) JUL]), user(KT, 'ky_thuat'));
check($G, 'Kỹ thuật: không có chi phí, baseline, khuyến nghị; cảnh báo chỉ phần của mình (1 thủ công)', !isset($k['cost']) && !isset($k['baseline']) && !isset($k['recommendations'])
    && $k['alerts']['open'] === 1 && $k['consumption']['dien']['baseline_value'] === null);
actAs(KTO, 'ke_toan');
$k = $dash->build($f(['period_id' => (string) JUL]), user(KTO, 'ke_toan'));
check($G, 'Kế toán: có chi phí + cảnh báo (xem), không có baseline (FN-06 chỉ BQL) / khuyến nghị (NEED BA)', isset($k['cost']) && isset($k['alerts']) && !isset($k['baseline']) && !isset($k['recommendations']));
actAs(BQL, 'bql');
check($G, 'dashboard CHỈ ĐỌC: dữ liệu nghiệp vụ + audit không đổi sau 3 lần dựng', snapshot($pdo) === $before);

// =========================================================== REPORT
$G = 'REPORT';
$show = ['cost' => true, 'names' => true];
$r = $report->build($rf(['period_id' => (string) JUN]), $show);
check($G, 'kỳ khóa 06/2024: 4 căn, đều chính thức; tổng phát sinh 4.773.552, điện 643 / nước 58 (tách)', count($r['rows']) === 4 && $r['totals']['official'] === 4 && $r['totals']['total_arising'] === 477355200
    && $r['totals']['consumption']['dien'] === 64300 && $r['totals']['consumption']['nuoc'] === 5800);
$r = $report->build($rf(['period_id' => (string) JUN, 'apartment' => 'BT1']), $show);
check($G, 'một căn (lọc "BT1"): 1 dòng, khớp FN-05 (phải thu 1.950.058), chủ hộ Phạm Văn A', count($r['rows']) === 1 && $r['rows'][0]['charge']['total_due'] === '1950058.00' && $r['rows'][0]['owner'] === 'Phạm Văn A');
$r = $report->build($rf(['period_id' => (string) JUN, 'zone_id' => '3']), $show);
check($G, 'tổng theo khu KHU-CC: 2 căn, 1.039.066', count($r['rows']) === 2 && $r['totals']['total_arising'] === 103906600);
$r = $report->build($rf(['period_id' => (string) JUL]), $show);
$states = array_column(array_map(static fn ($x) => ['c' => $x['apartment']['code'], 's' => $x['charge_state']], $r['rows']), 's', 'c');
check($G, 'kỳ mở 07/2024: BT1 & CC-2306 chính thức; CC-2305 chưa xác nhận; S01 CẦN TÍNH LẠI (đổi số xe sau khi tính)', $states === ['BT1' => 'official', 'CC-2305' => 'unconfirmed', 'CC-2306' => 'official', 'S01' => 'stale'], json_encode($states));
check($G, '… tổng chính thức chỉ cộng 2 bản, loại 2', $r['totals']['official'] === 2 && $r['totals']['excluded'] === 2
    && $r['totals']['total_arising'] === (int) round((float) q($pdo, 'SELECT SUM(total_arising) * 100 FROM apartment_charges WHERE billing_period_id = ? AND apartment_id IN (1, 4)', [JUL])));
$r = $report->build($rf(['period_id' => (string) $aug]), $show);
check($G, 'kỳ chưa có bản tính (08/2024): căn "Chưa có bản tính", tổng chính thức 0', $r['rows'] !== [] && $r['totals']['official'] === 0 && array_unique(array_column($r['rows'], 'charge_state')) === ['missing']);
$r = $report->build($rf(['period_id' => (string) JUN]), ['cost' => false, 'names' => false]);
check($G, 'xem giới hạn (Kỹ thuật): không có bản tính / họ tên trong dữ liệu báo cáo', array_filter($r['rows'], static fn ($x) => $x['charge'] !== null || $x['owner'] !== null) === []);
$r = $report->build($rf(['period_id' => (string) JUN, 'utility_type' => 'nuoc']), $show);
check($G, 'lọc tiện ích nước: chỉ cột nước', $r['utilities'] === ['nuoc'] && !isset($r['totals']['consumption']['dien']));

// =========================================================== CSV
$G = 'CSV';
$pdo->exec("UPDATE zones SET name = '=HYPERLINK(\"http://x\"), \"Khu\"\nCC' WHERE id = 3"); // fixture: tên khu chứa công thức, dấu phẩy, ngoặc kép, xuống dòng
$csv = $report->csv($report->build($rf(['period_id' => (string) JUN]), $show));
check($G, 'UTF-8 có BOM; tiếng Việt giữ nguyên ("Chủ hộ", "Phạm Văn A")', str_starts_with($csv, CsvWriter::BOM) && str_contains($csv, 'Chủ hộ') && str_contains($csv, 'Phạm Văn A'));
$h = fopen('php://temp', 'r+');
fwrite($h, substr($csv, 3));
rewind($h);
$parsed = [];
while (($row = fgetcsv($h, 0, ',', '"', '')) !== false) {
    $parsed[] = $row;
}
fclose($h);
$cc = array_values(array_filter($parsed, static fn ($row) => ($row[3] ?? '') === 'CC-2305'))[0] ?? [];
check($G, 'dấu phẩy / ngoặc kép / xuống dòng: đọc lại đúng 14 cột, giá trị nguyên vẹn', count($parsed[0]) === 14 && count($cc) === 14 && $cc[2] === "'=HYPERLINK(\"http://x\"), \"Khu\"\nCC", json_encode($cc, JSON_UNESCAPED_UNICODE));
check($G, 'chống CSV formula injection: ô văn bản bắt đầu = + - @ được thêm \'', CsvWriter::text('=1+1') === "'=1+1" && CsvWriter::text('+SUM(A1)') === "'+SUM(A1)" && CsvWriter::text('-2+3') === "'-2+3"
    && CsvWriter::text('@cmd') === "'@cmd" && CsvWriter::text("\tx") === "'\tx" && CsvWriter::text('Khu A') === 'Khu A');
check($G, 'ô số giữ nguyên (số âm không bị thêm \')', CsvWriter::number('-150.00') === '-150.00');
check($G, 'ô trống: căn không có chủ hộ (CC-2305 chỉ có khách thuê) → ""', ($cc[4] ?? 'x') === '');
check($G, 'dòng tổng: không cộng kWh với m³ (2 cột riêng 643.00 / 58.00), tổng tiền 4.773.552', end($parsed)[5] === '643.00' && end($parsed)[6] === '58.00' && end($parsed)[10] === '4773552.00');
$big = CsvWriter::build(['a', 'b'], (static function () {
    for ($i = 0; $i < 5000; $i++) {
        yield [CsvWriter::text("Căn {$i}, \"x\""), (string) $i];
    }
})());
check($G, 'dữ liệu lớn: 5.000 dòng → 5.001 bản ghi CSV hợp lệ', substr_count($big, "\r\n") === 5001 && count(str_getcsv(explode("\r\n", $big)[4999])) === 2);

// =========================================================== BILL NOTICE (FN-09.02)
$G = 'BILL';
$bills = new BillNoticeService();
$n = $bills->forCharge(1);
check($G, 'kỳ khóa, căn thường (BT1 06/2024): tổng phải thu = FN-05 (1.950.058), chủ hộ, 2 công tơ, VAT 64.348', $n['charge']['total_due'] === '1950058.00' && $n['owner'] === 'Phạm Văn A'
    && count($n['meters']) === 2 && $n['charge']['electricity_vat_amount'] === '64348.00');
check($G, '… bản tính seed không có snapshot → phí theo khoản + ghi giới hạn (không bịa cơ sở tính)', $n['fees'] === null);
$bt1 = $bills->forCharge($cid(1));
$fees = array_column($bt1['fees'] ?? [], null, 'fee_type');
check($G, 'kỳ 07 (có snapshot): phí DV 80 m² × 3.000, BHCN, gửi xe 1 ô tô × 500.000; VAT từng công tơ 8%', ($fees['dich_vu_co_o']['base_quantity'] ?? '') === '80.00'
    && ($fees['gui_o_to']['amount'] ?? '') === '500000.00' && isset($fees['bao_hiem_chay_no']) && ($bt1['meters'][0]['snapshot']['vat_percent'] ?? '') === '8.00');
check($G, 'không nợ cũ: phải thu = phát sinh', $bt1['charge']['old_debt_amount'] === '0.00' && $bt1['charge']['total_due'] === $bt1['charge']['total_arising']);
$cc6 = $bills->forCharge($cid(4));
check($G, 'nợ cũ 50.000 (CC-2306): phải thu = phát sinh + 50.000', bcsub($cc6['charge']['total_due'], $cc6['charge']['total_arising'], 2) === '50000.00');
try {
    $bills->forCharge($cid(3));
    check($G, 'bản tính chưa xác nhận (CC-2305) → "Dữ liệu căn hộ CC-2305 chưa được xác nhận."', false);
} catch (ValidationException $e) {
    check($G, 'bản tính chưa xác nhận (CC-2305) → "Dữ liệu căn hộ CC-2305 chưa được xác nhận."', $e->getMessage() === 'Dữ liệu căn hộ CC-2305 chưa được xác nhận.');
}
try {
    $bills->forCharge($cid(2));
    check($G, 'bản tính CẦN TÍNH LẠI (S01) → không xuất', false);
} catch (ValidationException $e) {
    check($G, 'bản tính CẦN TÍNH LẠI (S01) → không xuất', str_contains($e->getMessage(), 'CẦN TÍNH LẠI'));
}
actAs(KTO, 'ke_toan');
$conf->confirm(JUL, [$cid(3) => $tok(3)]);
$cc5 = $bills->forCharge($cid(3));
check($G, 'nhiều công tơ + kinh doanh (CC-2305): 2 công tơ điện, công tơ KD 1 dòng đồng giá [0, NULL); chủ hộ trống (chỉ khách thuê)', count(array_filter($cc5['meters'], static fn ($m) => $m['reading']['utility_type'] === 'dien')) === 2
    && count(array_filter($cc5['meters'], static fn ($m) => $m['reading']['usage_type'] === 'kinh_doanh' && count($m['lines']) === 1 && $m['lines'][0]['tier_to'] === null)) === 1 && $cc5['owner'] === null);
$batch = $bills->forPeriod(JUL);
check($G, 'hàng loạt kỳ 07: 3 phiếu, 1 bị loại (S01 CẦN TÍNH LẠI) kèm lý do', count($batch['notices']) === 3 && count($batch['skipped']) === 1 && str_contains($batch['skipped'][0]['apartment_code'], 'S01'));
try {
    $bills->forCharge(999999);
    check($G, 'bản tính không tồn tại → lỗi', false);
} catch (ValidationException $e) {
    check($G, 'bản tính không tồn tại → lỗi', true);
}
$before = snapshot($pdo);
$bills->forPeriod(JUL);
$bills->forCharge(1);
$report->build($rf(['period_id' => (string) JUL]), $show);
check($G, 'xem phiếu / báo cáo CHỈ ĐỌC: không đổi trạng thái bản tính, issued_at, audit', snapshot($pdo) === $before && (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE issued_at IS NOT NULL AND billing_period_id = ?', [JUL]) === 0);

// ===========================================================
$pass = 0;
$current = '';
foreach ($results as [$g, $n, $ok, $dd]) {
    if ($g !== $current) {
        echo "\n[{$g}]\n";
        $current = $g;
    }
    echo ($ok ? '  PASS ' : '  FAIL ') . $n . ($ok || $dd === '' ? '' : "  -> {$dd}") . "\n";
    $pass += $ok ? 1 : 0;
}
$total = count($results);
echo "\n{$pass}/{$total} PASS (service test, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
