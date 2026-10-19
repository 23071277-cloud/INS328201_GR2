<?php

declare(strict_types=1);

/**
 * STEP 10 - Test cấp Service FN-06 (baseline, so sánh, mục tiêu, theo dõi, đồng thời).
 * Chạy trên DATABASE TẠM:  php tests/fn06_service_test.php energy_dss_fn06_test
 * Seed: kỳ 06/2024 đã khoá (8 chỉ số), kỳ 07/2024 mở, baseline #1 (BT1 điện, kỳ trước = 343, hiệu lực 01/07/2024).
 * Fixture: tạo kỳ 03-05/2024 + chỉ số qua Service, rồi đặt trạng thái Đã khóa bằng SQL (chỉ dữ liệu thử).
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
use App\Services\ApartmentService;
use App\Services\BaselineService;
use App\Services\BillingPeriodService;
use App\Services\ConsumptionComparisonService;
use App\Services\ConsumptionTargetService;
use App\Services\MeterReadingService;
use App\Services\MeterService;
use App\Services\ReadingApprovalService;
use App\Services\ScopeConsumptionService;

$pdo = Database::connection();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
$_SERVER['REMOTE_ADDR'] = 'fn06-service-test';
const BQL = 1;
const KT = 2;
const JUN = 1;
const JUL = 2;

function actAs(int $id, string $role): void
{
    $_SESSION = ['user_id' => $id, 'user_role' => $role, 'username' => $role, 'full_name' => $role];
}

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

function expectError(string $g, string $n, string $field, callable $fn, string $contains = ''): void
{
    try {
        $fn();
        check($g, $n, false, 'không có lỗi');
    } catch (ValidationException $e) {
        $msg = json_encode($e->errors(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ok = isset($e->errors()[$field]) && ($contains === '' || str_contains($msg, $contains));
        check($g, $n, $ok, $ok ? '' : $msg);
    }
}

function expectOk(string $g, string $n, callable $fn)
{
    try {
        $r = $fn();
        check($g, $n, true);

        return $r;
    } catch (ValidationException $e) {
        check($g, $n, false, json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

        return null;
    }
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

function auditCount(PDO $pdo, string $action): int
{
    return (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]);
}

function race(string $db, array $jobs): array
{
    $start = microtime(true) + 1.5;
    $procs = [];
    $pipes = [];
    foreach ($jobs as $i => $job) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/fn06_worker.php') . ' ' . escapeshellarg($db) . ' ' . sprintf('%F', $start)
            . ' ' . implode(' ', array_map('escapeshellarg', array_map('strval', $job)));
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $out = [];
    foreach ($procs as $i => $p) {
        $raw = stream_get_contents($pipes[$i][1]);
        $out[$i] = json_decode((string) $raw, true) ?? ['ok' => false, 'message' => $raw . stream_get_contents($pipes[$i][2])];
        proc_close($p);
    }

    return $out;
}

$readings = new MeterReadingService();
$approval = new ReadingApprovalService();
$baselines = new BaselineService();
$comparison = new ConsumptionComparisonService();
$targets = new ConsumptionTargetService();

function enter(int $periodId, int $meterId, string $csc, bool $approve = true): int
{
    global $readings, $approval;
    actAs(KT, 'ky_thuat');
    $r = $readings->create($periodId, $meterId, ['current_reading' => $csc, 'note' => '']);
    if ($approve) {
        actAs(BQL, 'bql');
        $approval->approve($periodId, [(int) $r['id'] => MeterReadingService::versionToken($readings->find((int) $r['id']))]);
    }

    return (int) $r['id'];
}

function cmp(string $type, int $id, string $utility, int $period): array
{
    global $comparison;

    return $comparison->compare($type, $id, $utility, $period);
}

// =========================================================== FIXTURE: kỳ 03-05/2024 (lịch sử) + chỉ số
actAs(BQL, 'bql');
$periodSvc = new BillingPeriodService();
$mar = $periodSvc->create(['month' => '3', 'year' => '2024'])['id'];
$apr = $periodSvc->create(['month' => '4', 'year' => '2024'])['id'];
$may = $periodSvc->create(['month' => '5', 'year' => '2024'])['id'];
foreach ([[$mar, '1300', '210'], [$apr, '1620', '222'], [$may, '1900', '236']] as [$pid, $e, $w]) {
    enter($pid, 1, $e);  // BT1 điện: 300, 320, 280
    enter($pid, 2, $w);  // BT1 nước: 10, 12, 14
}
$pdo->exec("UPDATE billing_periods SET status = 'da_khoa', locked_at = NOW() WHERE id IN ({$mar}, {$apr}, {$may})");

// =========================================================== BASELINE (FN-06.01)
$G = 'BASELINE';
$jul1 = '2024-07-01';
$r = $baselines->compute('apartment', 1, 'dien', 'ky_truoc', 0, null, $jul1);
check($G, 'kỳ trước tồn tại: BT1 điện = CL 06/2024 = 343', $r['status'] === 'ok' && $r['value'] === 34300 && $r['periods_used'][0]['period_code'] === '06/2024');
$r = $baselines->compute('apartment', 1, 'dien', 'trung_binh_n_ky', 3, null, $jul1);
check($G, 'trung bình 3 kỳ: (343 + 280 + 320) ÷ 3 = 314,33 (half-up)', $r['status'] === 'ok' && $r['value'] === 31433 && count($r['periods_used']) === 3, json_encode($r));
$r = $baselines->compute('apartment', 1, 'dien', 'trung_binh_n_ky', 4, null, $jul1);
check($G, 'trung bình 4 kỳ: (343 + 280 + 320 + 300) ÷ 4 = 310,75', $r['value'] === 31075);
$r = $baselines->compute('apartment', 1, 'dien', 'trung_binh_n_ky', 5, null, $jul1);
check($G, 'thiếu lịch sử (cần 5, có 4) → INSUFFICIENT_HISTORY, không coi thiếu là 0', $r['status'] === 'insufficient_history' && $r['value'] === null && str_contains((string) $r['message'], 'INSUFFICIENT_HISTORY'));
$r = $baselines->compute('apartment', 1, 'dien', 'trung_binh_n_ky', 2, null, $jul1);
check($G, 'N = 2 < 3 → cảnh báo độ tin cậy thấp ([ĐX])', $r['status'] === 'ok' && $r['warnings'] !== []);
$r = $baselines->compute('apartment', 1, 'dien', 'ky_truoc', 0, null, '2024-03-01');
check($G, 'không có kỳ trước → INSUFFICIENT_HISTORY', $r['status'] === 'insufficient_history');
$r = $baselines->compute('apartment', 1, 'nuoc', 'trung_binh_n_ky', 3, null, $jul1);
check($G, 'điện và nước tách riêng: BT1 nước TB 3 kỳ = (16 + 14 + 12) ÷ 3 = 14', $r['value'] === 1400);
$r = $baselines->compute('apartment', 4, 'dien', 'ky_truoc', 0, null, $jul1);
check($G, 'baseline = 0 hợp lệ (CC-2306 CL 06/2024 = 0)', $r['status'] === 'ok' && $r['value'] === 0);
$r = $baselines->compute('apartment', 2, 'dien', 'trung_binh_n_ky', 2, null, $jul1);
check($G, 'kỳ không có dữ liệu của phạm vi (S01 tháng 3-5) bị bỏ qua, không tính là 0 → thiếu lịch sử', $r['status'] === 'insufficient_history' && count($r['skipped']) === 3);
$r = $baselines->compute('building', 1, 'dien', 'ky_truoc', 0, null, $jul1);
check($G, 'nhiều căn / nhiều công tơ: tòa A điện 06/2024 = 343 + 150 + 150 + 0 = 643', $r['value'] === 64300);
$r = $baselines->compute('zone', 3, 'dien', 'ky_truoc', 0, null, $jul1);
check($G, 'khu KHU-CC điện 06/2024 = 150 + 0 = 150', $r['value'] === 15000);
$r = $baselines->compute('meter', 1, 'dien', 'ky_truoc', 0, null, $jul1);
check($G, 'phạm vi công tơ: BT1-DIEN = 343', $r['value'] === 34300);
$pdo->exec("UPDATE billing_periods SET status = 'mo_lai' WHERE id = {$may}");
$r = $baselines->compute('apartment', 1, 'dien', 'trung_binh_n_ky', 3, null, $jul1);
check($G, 'kỳ đang Mở lại (05/2024) không dùng: TB = (343 + 320 + 300) ÷ 3 = 321', $r['value'] === 32100 && $r['skipped'][0]['period_code'] === '05/2024');
$r = $baselines->compute('apartment', 1, 'dien', 'ky_truoc', 0, null, '2024-06-15');
check($G, 'kỳ trước liền kề chưa khóa → INSUFFICIENT_HISTORY (không lùi tiếp - R3)', $r['status'] === 'insufficient_history');
$pdo->exec("UPDATE billing_periods SET status = 'da_khoa' WHERE id = {$may}");
$r = $baselines->compute('apartment', 1, 'dien', 'cung_ky_nam_truoc', 0, null, '2025-06-15');
check($G, 'cùng kỳ năm trước (hiệu lực 15/06/2025 → 06/2024) = 343', $r['status'] === 'ok' && $r['value'] === 34300);
$r = $baselines->compute('apartment', 1, 'dien', 'cung_ky_nam_truoc', 0, null, $jul1);
check($G, 'cùng kỳ năm trước không có (07/2023) → INSUFFICIENT_HISTORY', $r['status'] === 'insufficient_history');

actAs(BQL, 'bql');
$preview = $baselines->preview(['scope_type' => 'zone', 'scope_id' => '3', 'utility_type' => 'dien', 'method' => 'ky_truoc', 'effective_date' => $jul1]);
check($G, 'tính thử (preview) không ghi DB', $preview['value'] === 15000 && (int) q($pdo, 'SELECT COUNT(*) FROM baselines') === 1);
$bZone = expectOk($G, 'lập baseline khu KHU-CC điện (kỳ trước) → lưu computed_value 150', fn () => $baselines->create(['scope_type' => 'zone', 'scope_id' => '3', 'utility_type' => 'dien', 'method' => 'ky_truoc', 'effective_date' => $jul1]));
check($G, '… audit CREATE_BASELINE kèm kỳ nguồn', q($pdo, 'SELECT computed_value FROM baselines WHERE id = ?', [$bZone]) === '150.00'
    && (json_decode((string) q($pdo, "SELECT new_value FROM audit_logs WHERE action = 'CREATE_BASELINE' AND entity_id = ?", [(string) $bZone]), true)['source_periods'][0]['period_code'] ?? '') === '06/2024');
expectError($G, 'thiếu lịch sử → không lưu', 'method', fn () => $baselines->create(['scope_type' => 'apartment', 'scope_id' => '1', 'utility_type' => 'dien', 'method' => 'trung_binh_n_ky', 'n_periods' => '5', 'effective_date' => '2024-07-02']), 'INSUFFICIENT_HISTORY');
$bWater = expectOk($G, 'nhập tay: BT1 nước = 10', fn () => $baselines->create(['scope_type' => 'apartment', 'scope_id' => '1', 'utility_type' => 'nuoc', 'method' => 'nhap_tay', 'manual_value' => '10', 'effective_date' => $jul1]));
$bApt3 = expectOk($G, 'nhập tay: CC-2305 điện = 400', fn () => $baselines->create(['scope_type' => 'apartment', 'scope_id' => '3', 'utility_type' => 'dien', 'method' => 'nhap_tay', 'manual_value' => '400', 'effective_date' => $jul1]));
$bZero = expectOk($G, 'kỳ trước = 0: CC-2306 điện', fn () => $baselines->create(['scope_type' => 'apartment', 'scope_id' => '4', 'utility_type' => 'dien', 'method' => 'ky_truoc', 'effective_date' => $jul1]));
$bBuild = expectOk($G, 'tòa A điện (kỳ trước = 643)', fn () => $baselines->create(['scope_type' => 'building', 'scope_id' => '1', 'utility_type' => 'dien', 'method' => 'ky_truoc', 'effective_date' => $jul1]));

$G = 'BASELINE_VALIDATION';
$base = ['scope_type' => 'apartment', 'scope_id' => '2', 'utility_type' => 'dien', 'method' => 'nhap_tay', 'manual_value' => '100', 'effective_date' => '2024-08-01'];
expectError($G, 'giá trị âm', 'manual_value', fn () => $baselines->create(array_merge($base, ['manual_value' => '-1'])));
expectError($G, 'giá trị không phải số', 'manual_value', fn () => $baselines->create(array_merge($base, ['manual_value' => 'abc'])));
expectError($G, 'scope không tồn tại', 'scope_id', fn () => $baselines->create(array_merge($base, ['scope_id' => '999'])), 'không tồn tại');
expectError($G, 'scope_type / scope_id không khớp (zone #9 không có)', 'scope_id', fn () => $baselines->create(array_merge($base, ['scope_type' => 'zone', 'scope_id' => '9'])), 'không khớp');
expectError($G, 'scope_type lạ', 'scope_id', fn () => $baselines->create(array_merge($base, ['scope_type' => 'city'])));
expectError($G, 'công tơ nước mà chọn tiện ích điện', 'utility_type', fn () => $baselines->create(array_merge($base, ['scope_type' => 'meter', 'scope_id' => '2'])));
expectError($G, 'tiện ích không hợp lệ', 'utility_type', fn () => $baselines->create(array_merge($base, ['utility_type' => 'gas'])));
expectError($G, 'phương pháp không hợp lệ', 'method', fn () => $baselines->create(array_merge($base, ['method' => 'du_bao'])));
expectError($G, 'N = 0', 'n_periods', fn () => $baselines->create(array_merge($base, ['method' => 'trung_binh_n_ky', 'n_periods' => '0'])));
expectError($G, 'ngày hiệu lực sai', 'effective_date', fn () => $baselines->create(array_merge($base, ['effective_date' => '2024-02-30'])));
expectError($G, 'ngày hiệu lực trong kỳ đã khóa (không hồi tố)', 'effective_date', fn () => $baselines->create(array_merge($base, ['effective_date' => '2024-06-30'])), 'kỳ đã khóa');
expectError($G, 'trùng (phạm vi, tiện ích, ngày hiệu lực) với baseline seed #1', 'effective_date', fn () => $baselines->create(['scope_type' => 'apartment', 'scope_id' => '1', 'utility_type' => 'dien', 'method' => 'nhap_tay', 'manual_value' => '1', 'effective_date' => $jul1]), 'Đã có baseline');

// =========================================================== COMPARISON (FN-06.02) trên kỳ 07/2024
$G = 'COMPARISON';
actAs(BQL, 'bql');
$m9 = (int) (new MeterService())->create(['apartment_id' => '3', 'meter_code' => 'CC2305-KD', 'utility_type' => 'dien', 'usage_type' => 'kinh_doanh', 'display_label' => 'KD', 'initial_reading' => '1000']);
$jul = [];
foreach ([1 => '1686', 2 => '232', 3 => '800', 4 => '160', 5 => '600', 6 => '74', 7 => '5', 8 => '0'] as $meter => $csc) {
    $jul[$meter] = enter(JUL, $meter, $csc, false);
}
$jul[9] = enter(JUL, $m9, '1100', false);
$c = cmp('apartment', 1, 'dien', JUL);
check($G, 'chỉ số còn Nháp → "Chưa đủ dữ liệu", không có giá trị / % (BR-11)', $c['data_status'] === 'incomplete' && $c['current_value'] === null && $c['percentage_change'] === null);
actAs(BQL, 'bql');
$items = [];
foreach ($jul as $id) {
    $items[$id] = MeterReadingService::versionToken($readings->find($id));
}
$approval->approve(JUL, $items);

$c = cmp('apartment', 1, 'dien', JUL);
check($G, 'hiện tại = baseline (343 = 343): chênh lệch 0, % 0, xu hướng "bằng"', $c['current_value'] === '343.00' && $c['baseline_value'] === '343.00' && $c['difference'] === '0.00' && $c['percentage_change'] === '0.00' && $c['trend'] === 'equal');
check($G, 'kỳ trước 06/2024 = 343, % so kỳ trước 0', $c['previous_value'] === '343.00' && $c['previous_percentage_change'] === '0.00');
$c = cmp('apartment', 1, 'nuoc', JUL);
check($G, 'hiện tại > baseline (nước 16 vs 10): +6, +60%', $c['difference'] === '6.00' && $c['percentage_change'] === '60.00' && $c['trend'] === 'up');
$c = cmp('apartment', 3, 'dien', JUL);
check($G, 'hiện tại < baseline + nhiều công tơ: CC-2305 = 150 (SH) + 100 (KD) = 250 vs 400 → −150, −37,5%', $c['current_value'] === '250.00' && $c['difference'] === '-150.00' && $c['percentage_change'] === '-37.50' && $c['trend'] === 'down');
$c = cmp('apartment', 4, 'dien', JUL);
check($G, 'baseline = 0: chênh lệch 5, % = null (không chia 0)', $c['baseline_value'] === '0.00' && $c['difference'] === '5.00' && $c['percentage_change'] === null && str_contains((string) $c['message'], 'Baseline = 0'));
$c = cmp('apartment', 4, 'nuoc', JUL);
check($G, 'kỳ trước = 0 và hiện tại = 0: % so kỳ trước = null', $c['previous_value'] === '0.00' && $c['current_value'] === '0.00' && $c['previous_percentage_change'] === null);
$c = cmp('apartment', 2, 'dien', JUL);
check($G, 'không có baseline → baseline/%/chênh lệch null, vẫn so được với kỳ trước', $c['baseline_value'] === null && $c['percentage_change'] === null && $c['previous_value'] === '150.00' && $c['previous_percentage_change'] === '0.00');
$c = cmp('apartment', 1, 'dien', $mar);
check($G, 'không có kỳ trước (03/2024 là kỳ đầu) → previous null', $c['previous_period_code'] === null && $c['previous_value'] === null && $c['current_value'] === '300.00');
$c = cmp('building', 1, 'dien', JUL);
check($G, 'gộp theo tòa: 343 + 150 + 250 + 5 = 748; kỳ trước 643; % so kỳ trước = 105/643 = 16,33%; baseline 643',
    $c['current_value'] === '748.00' && $c['previous_value'] === '643.00' && $c['previous_percentage_change'] === '16.33' && $c['percentage_change'] === '16.33');
$c = cmp('zone', 3, 'dien', JUL);
check($G, 'gộp theo khu KHU-CC: 250 + 5 = 255; baseline khu 150 → +70%', $c['current_value'] === '255.00' && $c['percentage_change'] === '70.00');
$cw = cmp('building', 1, 'nuoc', JUL);
check($G, 'điện và nước không cộng chung: tòa nước = 16 + 30 + 12 + 0 = 58', $cw['current_value'] === '58.00');
$c = cmp('meter', $m9, 'dien', JUL);
check($G, 'phạm vi công tơ KD mới: 100, chưa có lịch sử → không so kỳ trước', $c['current_value'] === '100.00' && $c['previous_status'] === 'no_data' && $c['previous_value'] === null);
$t = $comparison->table(JUL, 'apartment', 'dien');
$sum = 0;
foreach ($t['rows'] as $row) {
    $sum += (int) round(((float) ($row['current_value'] ?? 0)) * 100);
}
check($G, 'bảng theo căn: không đếm trùng (Σ căn = tòa = 748)', $sum === 74800 && count($t['rows']) === 4);
$t2 = $comparison->table(JUN, 'apartment', 'dien');
$bt1 = array_values(array_filter($t2['rows'], static fn ($r) => $r['scope_id'] === 1))[0];
check($G, 'chi phí tham khảo (bản tính đã xác nhận 06/2024): BT1 điện 868.698', $bt1['current_cost'] === '868698.00');
check($G, 'không sinh cảnh báo (bảng alerts không đổi)', (int) q($pdo, "SELECT COUNT(*) FROM alerts WHERE alert_type = 'threshold'") === 0); // J-16 (patch 005) sinh khi duyệt chỉ số, không do FN-06

// =========================================================== TARGET (FN-06.03) + TRACKING (FN-06.04)
$G = 'TARGET';
actAs(BQL, 'bql');
$mk = fn (string $type, string $id, string $u, string $value, int $period = JUL) => fn () => $targets->create(['scope_type' => $type, 'scope_id' => $id, 'utility_type' => $u, 'billing_period_id' => (string) $period, 'target_value' => $value]);
check($G, 'seed đã có mục tiêu BT1 điện kỳ 07 (320) → đặt lại bị chặn trùng', (int) q($pdo, "SELECT COUNT(*) FROM consumption_targets WHERE scope_type = 'apartment' AND scope_id = 1 AND utility_type = 'dien' AND billing_period_id = 2") === 1);
$t0 = 1; // mục tiêu seed: BT1 điện 320
$t1 = expectOk($G, 'đặt mục tiêu CC-2305 điện 300', $mk('apartment', '3', 'dien', '300'));
$t2 = expectOk($G, 'BT1 nước 16', $mk('apartment', '1', 'nuoc', '16'));
$t3 = expectOk($G, 'tòa A điện 700', $mk('building', '1', 'dien', '700'));
$t4 = expectOk($G, 'CC-2306 điện = 0', $mk('apartment', '4', 'dien', '0'));
$t5 = expectOk($G, 'CC-2306 nước = 0', $mk('apartment', '4', 'nuoc', '0'));
$t6 = expectOk($G, 'khu KHU-CC điện 300', $mk('zone', '3', 'dien', '300'));
check($G, 'audit CREATE_CONSUMPTION_TARGET', auditCount($pdo, 'CREATE_CONSUMPTION_TARGET') === 6);
expectError($G, 'trùng (phạm vi, tiện ích, kỳ)', 'billing_period_id', $mk('apartment', '1', 'dien', '500'), 'Đã có mục tiêu');
expectError($G, 'mục tiêu âm', 'target_value', $mk('apartment', '2', 'dien', '-5'));
expectError($G, 'mục tiêu không phải số', 'target_value', $mk('apartment', '2', 'dien', '1e3'));
expectError($G, 'phạm vi không tồn tại', 'scope_id', $mk('apartment', '999', 'dien', '5'));
expectError($G, 'phạm vi công tơ không dùng cho mục tiêu', 'scope_id', $mk('meter', '1', 'dien', '5'));
expectError($G, 'tiện ích sai', 'utility_type', $mk('apartment', '2', 'gas', '5'));
expectError($G, 'kỳ không tồn tại', 'billing_period_id', $mk('apartment', '2', 'dien', '5', 999));

$tr = fn ($id) => $targets->track((int) $id);
$k = $tr($t1);
check($G, 'thực tế < mục tiêu (CC-2305: 250 < 300): Đạt, chênh lệch −50, 83,33%', $k['target_status'] === 'dat' && $k['variance'] === '-50.00' && $k['achievement_percentage'] === '83.33');
$k = $tr($t0);
check($G, 'thực tế > mục tiêu (BT1 343 > 320 seed): Không đạt, +23, 107,19%', $k['target_status'] === 'khong_dat' && $k['variance'] === '23.00' && $k['achievement_percentage'] === '107.19');
$k = $tr($t2);
check($G, 'thực tế = mục tiêu (16 = 16): Đạt, 100%', $k['target_status'] === 'dat' && $k['achievement_percentage'] === '100.00' && $k['variance'] === '0.00');
$k = $tr($t3);
check($G, 'thực tế > mục tiêu (tòa 748 > 700): Không đạt, +48, 106,86%', $k['target_status'] === 'khong_dat' && $k['variance'] === '48.00' && $k['achievement_percentage'] === '106.86');
$k = $tr($t4);
check($G, 'mục tiêu = 0, thực tế 5: Không đạt, % = null (không chia 0)', $k['target_status'] === 'khong_dat' && $k['achievement_percentage'] === null);
$k = $tr($t5);
check($G, 'mục tiêu = 0, thực tế = 0: Đạt, % = null', $k['target_status'] === 'dat' && $k['actual_value'] === '0.00' && $k['achievement_percentage'] === null);
$k = $tr($t6);
check($G, 'mục tiêu khu so với tiêu thụ KHU (255), không lấy căn', $k['actual_value'] === '255.00' && $k['target_status'] === 'dat');
$aug = $periodSvc->create(['month' => '8', 'year' => '2024'])['id'];
$t7 = expectOk($G, 'mục tiêu kỳ chưa có chỉ số (08/2024)', $mk('apartment', '1', 'dien', '300', $aug));
check($G, '… → "Chưa đủ dữ liệu", không Đạt/Không đạt', $tr($t7)['target_status'] === 'chua_du_du_lieu' && $tr($t7)['actual_value'] === null);
$track = $targets->tracking(['period_id' => (string) JUL, 'utility_type' => 'dien', 'scope_type' => 'apartment']);
check($G, 'tỷ lệ đạt (BR-34) căn/điện kỳ 07: 1 đạt (CC-2305) / 3 được theo dõi = 33,33%', $track['summary'][0]['attainment_rate'] === '33.33' && $track['summary'][0]['tracked'] === 3);
$row = $targets->find((int) $t1);
expectError($G, 'sửa với token cũ → từ chối (không ghi đè âm thầm)', '_', fn () => $targets->update((int) $t1, ['target_value' => '410', 'version_token' => 'old']), 'người khác');
expectOk($G, 'sửa giá trị 300 → 350 (token đúng)', fn () => $targets->update((int) $t1, ['target_value' => '350', 'version_token' => $row['version_token']]));
check($G, '… audit UPDATE_CONSUMPTION_TARGET (cũ 300 → mới 350)', auditCount($pdo, 'UPDATE_CONSUMPTION_TARGET') === 1 && q($pdo, 'SELECT target_value FROM consumption_targets WHERE id = ?', [$t1]) === '350.00');
expectError($G, 'dùng lại token cũ sau khi đã sửa', '_', fn () => $targets->update((int) $t1, ['target_value' => '360', 'version_token' => $row['version_token']]));

// =========================================================== VERSION / LỊCH SỬ KỲ KHÓA (R6, R9)
$G = 'HISTORY';
$pdo->exec("UPDATE billing_periods SET status = 'da_khoa', locked_at = NOW() WHERE id = " . JUL); // fixture: khoá 07/2024
$before = [cmp('apartment', 1, 'dien', JUL), cmp('zone', 3, 'dien', JUL), cmp('building', 1, 'dien', JUL)];
check($G, 'phiên bản đã dùng cho kỳ khóa: không sửa / ngừng được', $baselines->editBlockReason($baselines->find((int) $bZone)) !== null);
expectError($G, '… update bị từ chối', 'effective_date', fn () => $baselines->update((int) $bZone, ['method' => 'nhap_tay', 'manual_value' => '1', 'effective_date' => $jul1, 'version_token' => $baselines->find((int) $bZone)['version_token']]), 'kỳ đã khóa');
expectError($G, 'phiên bản mới hiệu lực trong kỳ đã khóa (15/07) → từ chối', 'effective_date', fn () => $baselines->create(['scope_type' => 'zone', 'scope_id' => '3', 'utility_type' => 'dien', 'method' => 'nhap_tay', 'manual_value' => '1', 'effective_date' => '2024-07-15']));
actAs(BQL, 'bql');
$future = expectOk($G, 'phiên bản mới hiệu lực 01/08 (sau kỳ khóa) cho khu', fn () => $baselines->create(['scope_type' => 'zone', 'scope_id' => '3', 'utility_type' => 'dien', 'method' => 'nhap_tay', 'manual_value' => '999', 'effective_date' => '2024-08-01']));
$cur = (new ApartmentService())->find(4);
(new ApartmentService())->update(4, ['building_id' => '1', 'zone_id' => '1', 'code' => 'CC-2306', 'area' => '70', 'occupancy_status' => 'khong_phat_sinh', 'updated_at' => $cur['updated_at']]);
$after = [cmp('apartment', 1, 'dien', JUL), cmp('zone', 3, 'dien', JUL), cmp('building', 1, 'dien', JUL)];
check($G, 'so sánh kỳ đã khóa tái lập được sau khi có baseline mới (01/08): baseline khu vẫn 150 (phiên bản cũ)', $after[1]['baseline_value'] === '150.00' && $after[0] == $before[0] && $after[2] == $before[2]);
check($G, 'giới hạn đã ghi nhận: đổi khu của căn (FN-02) làm đổi số gộp theo khu (không lưu lịch sử thành viên khu - R10)', $after[1]['current_value'] === '250.00' && $before[1]['current_value'] === '255.00');
(new ApartmentService())->update(4, ['building_id' => '1', 'zone_id' => '3', 'code' => 'CC-2306', 'area' => '70', 'occupancy_status' => 'khong_phat_sinh', 'updated_at' => (new ApartmentService())->find(4)['updated_at']]);
$f = $baselines->find((int) $future);
expectOk($G, 'sửa phiên bản chưa dùng (01/08): 999 → 900 (UPDATE_BASELINE)', fn () => $baselines->update((int) $future, ['method' => 'nhap_tay', 'manual_value' => '900', 'effective_date' => '2024-08-01', 'version_token' => $f['version_token']]));
expectError($G, '… sửa lại với token cũ → từ chối', '_', fn () => $baselines->update((int) $future, ['method' => 'nhap_tay', 'manual_value' => '800', 'effective_date' => '2024-08-01', 'version_token' => $f['version_token']]), 'người khác');
expectOk($G, 'ngừng phiên bản chưa dùng → Hết hiệu lực (DEACTIVATE_BASELINE)', fn () => $baselines->deactivate((int) $future, $baselines->find((int) $future)['version_token']));
expectError($G, 'ngừng lần 2 → lỗi', '_', fn () => $baselines->deactivate((int) $future, $baselines->find((int) $future)['version_token']), 'Hết hiệu lực');
check($G, 'audit UPDATE_BASELINE / DEACTIVATE_BASELINE', auditCount($pdo, 'UPDATE_BASELINE') === 1 && auditCount($pdo, 'DEACTIVATE_BASELINE') === 1);
// mở lại kỳ nguồn + sửa chỉ số -> baseline không tự đổi, chi tiết báo nguồn đã đổi
$pdo->exec("UPDATE billing_periods SET status = 'mo_lai' WHERE id = " . JUN);
actAs(KT, 'ky_thuat');
$readings->update(1, ['current_reading' => '1350', 'note' => 'mở lại sửa', 'version_token' => MeterReadingService::versionToken($readings->find(1))]);
$d = $baselines->detail(1);
check($G, 'mở lại kỳ nguồn + sửa chỉ số: baseline #1 giữ 343 (không tự đổi), chi tiết báo "nguồn đã thay đổi" (NEED BA)', $d['value_cents'] === 34300 && $d['source_changed'] === true);
check($G, 'kỳ đang Mở lại: tiêu thụ hiện tại "Chưa đủ dữ liệu" (chỉ số về Nháp)', cmp('apartment', 1, 'dien', JUN)['data_status'] === 'incomplete');

// =========================================================== CONCURRENCY
$G = 'CONCURRENCY';
$out = race($testDb, [[BQL, 'bql', 'target_create', 'apartment', 2, 'nuoc', JUL, '40'], [BQL, 'bql', 'target_create', 'apartment', 2, 'nuoc', JUL, '45']]);
check($G, '2 BQL tạo mục tiêu cùng phạm vi/kỳ → đúng 1 thành công (UNIQUE), 1 "Đã có mục tiêu"',
    count(array_filter($out, static fn ($o) => $o['ok'])) === 1 && (int) q($pdo, "SELECT COUNT(*) FROM consumption_targets WHERE scope_type = 'apartment' AND scope_id = 2 AND utility_type = 'nuoc'") === 1
    && str_contains(json_encode($out, JSON_UNESCAPED_UNICODE), 'Đã có mục tiêu'), json_encode($out, JSON_UNESCAPED_UNICODE));
$row = $targets->find((int) $t3);
$out = race($testDb, [[BQL, 'bql', 'target_update', $t3, '710', $row['version_token']], [BQL, 'bql', 'target_update', $t3, '720', $row['version_token']]]);
$final = q($pdo, 'SELECT target_value FROM consumption_targets WHERE id = ?', [$t3]);
$winner = array_values(array_filter($out, static fn ($o) => $o['ok']));
check($G, '2 BQL sửa cùng mục tiêu (cùng token) → 1 thành công, 1 bị từ chối, không ghi đè âm thầm',
    count($winner) === 1 && in_array($final, ['710.00', '720.00'], true) && auditCount($pdo, 'UPDATE_CONSUMPTION_TARGET') === 2, json_encode($out, JSON_UNESCAPED_UNICODE) . " final={$final}");
$out = race($testDb, [[BQL, 'bql', 'baseline_create', 'apartment', 2, 'nuoc', '30', '2024-09-01'], [BQL, 'bql', 'baseline_create', 'apartment', 2, 'nuoc', '31', '2024-09-01']]);
check($G, '2 BQL lập baseline trùng (phạm vi, tiện ích, ngày) → đúng 1 thành công (khóa dòng phạm vi)',
    count(array_filter($out, static fn ($o) => $o['ok'])) === 1 && (int) q($pdo, "SELECT COUNT(*) FROM baselines WHERE scope_type = 'apartment' AND scope_id = 2 AND utility_type = 'nuoc' AND effective_date = '2024-09-01'") === 1,
    json_encode($out, JSON_UNESCAPED_UNICODE));

// =========================================================== FN-07 interface + không sinh cảnh báo
$G = 'INTERFACE';
$dto = cmp('apartment', 3, 'dien', JUL);
$keys = ['current_value', 'previous_value', 'baseline_value', 'difference', 'percentage_change', 'data_status'];
check($G, 'compare() trả DTO chuẩn cho FN-07: ' . implode(', ', $keys), array_diff($keys, array_keys($dto)) === []);
$k = $targets->track((int) $t1) ?? [];
$keys = ['target_value', 'actual_value', 'variance', 'achievement_percentage', 'target_status', 'data_status'];
check($G, 'track() trả DTO chuẩn cho FN-07: ' . implode(', ', $keys), array_diff($keys, array_keys($k)) === []);
check($G, 'percent(): không NaN/Infinity — mẫu số 0 → null; làm tròn half-up đối xứng', ScopeConsumptionService::percent(5, 0) === null && ScopeConsumptionService::percent(1, 3) === '33.33'
    && ScopeConsumptionService::percent(-1, 3) === '-33.33' && ScopeConsumptionService::percent(2, 3) === '66.67');
check($G, 'FN-06 không tạo cảnh báo / khuyến nghị', (int) q($pdo, "SELECT COUNT(*) FROM alerts WHERE alert_type = 'threshold'") === 0 && (int) q($pdo, 'SELECT COUNT(*) FROM recommendations') === 0);

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
echo "\n{$pass}/{$total} PASS (service test, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
