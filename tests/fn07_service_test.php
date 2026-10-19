<?php

declare(strict_types=1);

/**
 * STEP 11 - Test cấp Service FN-07 (rule, đánh giá tự động, thủ công, trạng thái, kiểm tra lại, điều chỉnh, đồng thời).
 * Chạy trên DATABASE TẠM:  php tests/fn07_service_test.php energy_dss_fn07_test
 * Seed: kỳ 06/2024 khoá, 07/2024 mở; rule #1 (điện, baseline, > 30%), #2 (nước, baseline, > 30%).
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
use App\Services\AlertStateMachine;
use App\Services\AlertVerificationService;
use App\Services\AlertWorkflowService;
use App\Services\BaselineService;
use App\Services\BillingCalculationService;
use App\Services\BillingPeriodService;
use App\Services\MeterReadingService;
use App\Services\MeterService;
use App\Services\ReadingApprovalService;

require_once __DIR__ . '/support/legacy_compat.php'; // STEP 4C: chạy nguyên văn trên schema v1 hoặc v2
$pdo = legacy_compat_install();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
$_SERVER['REMOTE_ADDR'] = 'fn07-service-test';
const BQL = 1;
const KT = 2;
const KTO = 3;
const KT2 = 5;
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

function alertRow(PDO $pdo, int $id): array
{
    $s = $pdo->prepare('SELECT * FROM alerts WHERE id = ?');
    $s->execute([$id]);

    return $s->fetch() ?: [];
}

function tok(int $alertId): string
{
    global $pdo;

    return AlertService::versionToken(alertRow($pdo, $alertId));
}

function auditCount(PDO $pdo, string $action, ?int $entityId = null): int
{
    return $entityId === null
        ? (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action])
        : (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE action = ? AND entity_id = ?', [$action, (string) $entityId]);
}

function race(string $db, array $jobs): array
{
    $start = microtime(true) + 1.5;
    $procs = [];
    $pipes = [];
    foreach ($jobs as $i => $job) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/fn07_worker.php') . ' ' . escapeshellarg($db) . ' ' . sprintf('%F', $start)
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
$rules = new AlertRuleService();
$eval = new AlertEvaluationService();
$alerts = new AlertService();
$wf = new AlertWorkflowService();
$vf = new AlertVerificationService();

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

/** id cảnh báo theo rule + công tơ (0 = cấp căn) + kỳ */
function alertFor(PDO $pdo, int $ruleId, int $meterId, int $periodId = JUL): int
{
    return (int) q($pdo, 'SELECT id FROM alerts WHERE alert_rule_id = ? AND dedupe_meter_id = ? AND billing_period_id = ?', [$ruleId, $meterId, $periodId]);
}

$rule = static fn (array $over = []): array => array_merge(['name' => 'Rule test', 'measure_type' => 'tieu_thu', 'utility_type' => 'dien', 'compare_base' => 'baseline',
    'operator' => 'gt', 'threshold_value' => '25', 'scope_type' => 'toan_toa', 'scope_value' => '', 'priority_level' => 'cao'], $over);

// =========================================================== FIXTURE
$pdo->exec("INSERT INTO users (id, username, password_hash, full_name, role, status) SELECT 5, 'kythuat02', password_hash, 'Kỹ thuật 2', 'ky_thuat', 'hoat_dong' FROM users WHERE id = 2");
actAs(BQL, 'bql');
$m9 = (int) (new MeterService())->create(['apartment_id' => '3', 'meter_code' => 'CC2305-KD', 'utility_type' => 'dien', 'usage_type' => 'kinh_doanh', 'display_label' => 'KD', 'initial_reading' => '1000']);
$rd = [];
foreach ([1 => '1800', 2 => '232', 3 => '800', 4 => '160', 5 => '600', 6 => '74', 7 => '5', 8 => '0'] as $meter => $csc) {
    $rd[$meter] = enter(JUL, $meter, $csc);
}
$rd[9] = enter(JUL, $m9, '1100');
actAs(BQL, 'bql');
$bl = new BaselineService();
$bl->create(['scope_type' => 'meter', 'scope_id' => '1', 'utility_type' => 'dien', 'method' => 'ky_truoc', 'effective_date' => '2024-07-01']);      // 343
$bl->create(['scope_type' => 'meter', 'scope_id' => '5', 'utility_type' => 'dien', 'method' => 'nhap_tay', 'manual_value' => '150', 'effective_date' => '2024-07-01']);
$bl->create(['scope_type' => 'meter', 'scope_id' => '7', 'utility_type' => 'dien', 'method' => 'ky_truoc', 'effective_date' => '2024-07-01']);      // 0

// =========================================================== RULE (FN-07.01)
$G = 'RULE';
actAs(BQL, 'bql');
$rTest = expectOk($G, 'tạo quy tắc hợp lệ (điện, baseline, > 25%, cao)', fn () => $rules->create($rule()));
check($G, '… audit CREATE_ALERT_RULE, trạng thái Bật', auditCount($pdo, 'CREATE_ALERT_RULE', (int) $rTest) === 1 && q($pdo, 'SELECT status FROM alert_rules WHERE id = ?', [$rTest]) === 'bat');
expectError($G, 'trùng y hệt quy tắc đang bật → từ chối', 'threshold_value', fn () => $rules->create($rule(['name' => 'khác tên'])), 'giống hệt');
expectError($G, 'toán tử ngoài schema (lt)', 'operator', fn () => $rules->create($rule(['operator' => 'lt'])));
expectError($G, 'ngưỡng âm', 'threshold_value', fn () => $rules->create($rule(['threshold_value' => '-5'])));
expectError($G, 'ngưỡng = 0', 'threshold_value', fn () => $rules->create($rule(['threshold_value' => '0'])));
expectError($G, 'ngưỡng không phải số', 'threshold_value', fn () => $rules->create($rule(['threshold_value' => 'abc'])));
expectError($G, 'ngưỡng % > 1000', 'threshold_value', fn () => $rules->create($rule(['threshold_value' => '1500'])));
expectError($G, 'tiện ích sai', 'utility_type', fn () => $rules->create($rule(['utility_type' => 'gas'])));
expectError($G, 'đối tượng đo sai', 'measure_type', fn () => $rules->create($rule(['measure_type' => 'tien_mat'])));
expectError($G, 'chi phí + baseline (không có baseline chi phí) → từ chối', 'compare_base', fn () => $rules->create($rule(['measure_type' => 'chi_phi'])));
expectError($G, 'chi phí + phạm vi loại sử dụng (G-2) → từ chối', 'scope_type', fn () => $rules->create($rule(['measure_type' => 'chi_phi', 'compare_base' => 'gia_tri_tuyet_doi', 'scope_type' => 'usage_type', 'scope_value' => 'sinh_hoat'])));
expectError($G, 'mốc "mục tiêu" không có trong schema → từ chối (quy tắc theo target: BLOCKED)', 'compare_base', fn () => $rules->create($rule(['compare_base' => 'muc_tieu'])));
expectError($G, 'phạm vi khu không tồn tại', 'scope_value', fn () => $rules->create($rule(['scope_type' => 'zone', 'scope_value' => '99'])));
expectError($G, 'thiếu tên', 'name', fn () => $rules->create($rule(['name' => ' '])));
$row = $rules->find((int) $rTest);
expectError($G, 'sửa với token cũ → từ chối', '_', fn () => $rules->update((int) $rTest, $rule(['threshold_value' => '35', 'version_token' => 'old'])), 'người khác');
expectOk($G, 'sửa ngưỡng 25 → 35 (UPDATE_ALERT_RULE)', fn () => $rules->update((int) $rTest, $rule(['threshold_value' => '35', 'version_token' => $row['version_token']])));
expectOk($G, 'Tắt quy tắc (DEACTIVATE_ALERT_RULE)', fn () => $rules->setStatus((int) $rTest, 'tat', $rules->find((int) $rTest)['version_token']));
expectError($G, 'đánh giá bằng quy tắc đang Tắt → từ chối', '_', fn () => $eval->evaluatePeriod(JUL, (int) $rTest), 'Tắt');
check($G, 'quy tắc Tắt không nằm trong lần đánh giá chung', !in_array((int) $rTest, array_map('intval', array_column((new App\Repositories\AlertRuleRepository())->activeRules(), 'id')), true));

// =========================================================== AUTO (FN-07.02)
$G = 'AUTO';
$dry = $eval->evaluatePeriod(JUL, 1, true);
// đếm cảnh báo ngưỡng FN-07 (alert_type = threshold): cảnh báo hệ thống J-16 (patch 005) có thể đã sinh khi duyệt chỉ số CC-2306
check($G, 'chạy thử quy tắc #1 (baseline > 30%): 1 trường hợp (BT1-DIEN +33,24%), không ghi DB', count($dry['would_create']) === 1 && (int) q($pdo, "SELECT COUNT(*) FROM alerts WHERE alert_type = 'threshold'") === 0);
$r = $eval->evaluatePeriod(JUL, 1);
$a1 = alertFor($pdo, 1, 1);
$row = alertRow($pdo, $a1);
check($G, 'trên ngưỡng: sinh 1 cảnh báo cho BT1-DIEN (457 vs baseline 343 = +33,24%)', count($r['created']) === 1 && $a1 > 0
    && $row['actual_value'] === '457.0000' && $row['compare_value'] === '343.0000' && $row['percent_change'] === '33.24', json_encode($r, JSON_UNESCAPED_UNICODE));
check($G, '… source=quy tắc, Mới, ưu tiên = quy tắc (S7), created_by NULL (hệ thống), gắn căn + công tơ + kỳ', $row['source'] === 'rule' && $row['status'] === 'moi' && $row['priority'] === 'trung_binh'
    && $row['created_by'] === null && (int) $row['apartment_id'] === 1 && (int) $row['meter_id'] === 1 && (int) $row['billing_period_id'] === JUL);
check($G, '… câu giải thích có giá trị, ngưỡng, quy tắc; không kết luận sự cố (BR-29)', str_contains($row['description'], '+33,24%') && str_contains($row['description'], '> 30%')
    && str_contains($row['description'], 'quy tắc #1') && str_contains($row['description'], 'không phải kết luận') && !preg_match('/rò rỉ|hỏng|gian lận/u', $row['description']));
$snap = json_decode((string) q($pdo, "SELECT new_value FROM audit_logs WHERE action = 'CREATE_ALERT_AUTO' AND entity_id = ?", [(string) $a1]), true);
check($G, '… snapshot quy tắc + DTO FN-06 trong audit CREATE_ALERT_AUTO; lịch sử (∅ → Mới)', ($snap['rule_snapshot']['threshold_value'] ?? '') === '30.0000' && ($snap['comparison']['baseline_value'] ?? '') === '343.00'
    && (int) q($pdo, 'SELECT COUNT(*) FROM alert_status_history WHERE alert_id = ? AND old_status IS NULL AND new_status = ?', [$a1, 'moi']) === 1);
check($G, 'baseline thiếu (S01, CC2305-KD) / baseline 0 (CC2306) / % so baseline 0 (CC2305 SH = 0%) → không sinh, có lý do',
    ($r['skipped']['Không có baseline hiệu lực (BR-26)'] ?? 0) === 2 && ($r['skipped']['% so baseline không xác định (baseline = 0)'] ?? 0) === 1 && ($r['skipped']['Trong ngưỡng'] ?? 0) === 1, json_encode($r['skipped'], JSON_UNESCAPED_UNICODE));
$r = $eval->evaluatePeriod(JUL, 1);
check($G, 'chạy lại → 0 mới, 1 đã có; vẫn 1 cảnh báo (BR-40)', $r['created'] === [] && $r['duplicates'] === 1 && (int) q($pdo, 'SELECT COUNT(*) FROM alerts WHERE alert_rule_id = 1') === 1);
$rGt = $rules->create($rule(['name' => 'gt 33.24', 'threshold_value' => '33.24']));
$rGte = $rules->create($rule(['name' => 'gte 33.24', 'operator' => 'gte', 'threshold_value' => '33.24']));
$rBelow = $rules->create($rule(['name' => 'gt 40', 'threshold_value' => '40']));
$x = $eval->evaluatePeriod(JUL, $rGt);
$y = $eval->evaluatePeriod(JUL, $rGte);
$z = $eval->evaluatePeriod(JUL, $rBelow);
check($G, 'đúng ngưỡng (generic operator): > 33,24 → 0, ≥ 33,24 → 1; dưới ngưỡng (> 40%) → 0', count($x['created']) === 0 && count($y['created']) === 1 && count($z['created']) === 0);
$rPrev = $rules->create($rule(['name' => 'kỳ trước > 20%', 'compare_base' => 'ky_truoc', 'threshold_value' => '20']));
$r = $eval->evaluatePeriod(JUL, $rPrev);
check($G, 'mốc kỳ trước: BT1 +33,24% sinh; CC2306 (kỳ trước 0 → % null) và CC2305-KD (không có kỳ trước) bỏ qua, không coi null = 0',
    count($r['created']) === 1 && ($r['skipped']['% so kỳ trước không xác định (kỳ trước = 0)'] ?? 0) === 1 && ($r['skipped']['Không có dữ liệu kỳ trước (BR-26)'] ?? 0) === 1, json_encode($r['skipped'], JSON_UNESCAPED_UNICODE));
$rAbs = $rules->create($rule(['name' => 'tuyệt đối > 90 kWh', 'compare_base' => 'gia_tri_tuyet_doi', 'threshold_value' => '90']));
$r = $eval->evaluatePeriod(JUL, $rAbs);
check($G, 'nhiều công tơ: tuyệt đối > 90 kWh → 4 cảnh báo (BT1, S01, CC2305 SH + KD riêng từng công tơ)', count($r['created']) === 4
    && (int) q($pdo, 'SELECT COUNT(DISTINCT meter_id) FROM alerts WHERE alert_rule_id = ? AND apartment_id = 3', [$rAbs]) === 2
    && q($pdo, 'SELECT compare_value FROM alerts WHERE alert_rule_id = ? AND meter_id = 1', [$rAbs]) === '90.0000');
$rKd = $rules->create($rule(['name' => 'KD > 90', 'compare_base' => 'gia_tri_tuyet_doi', 'threshold_value' => '91', 'scope_type' => 'usage_type', 'scope_value' => 'kinh_doanh']));
$r = $eval->evaluatePeriod(JUL, $rKd);
check($G, 'phạm vi loại sử dụng KD: chỉ công tơ KD (S01 150, CC2305-KD 100)', count($r['created']) === 2 && $r['evaluated'] === 2);
$rZone = $rules->create($rule(['name' => 'Khu CC', 'compare_base' => 'gia_tri_tuyet_doi', 'threshold_value' => '1', 'scope_type' => 'zone', 'scope_value' => '3']));
$r = $eval->evaluatePeriod(JUL, $rZone);
check($G, 'phạm vi khu KHU-CC (theo danh mục hiện tại): chỉ công tơ căn CC-2305/2306 có CL > 1', count($r['created']) === 3 && $r['evaluated'] === 3);
$aug = (new BillingPeriodService())->create(['month' => '8', 'year' => '2024'])['id'];
enter($aug, 1, '1900', false);
$r = $eval->evaluatePeriod($aug, $rAbs);
check($G, 'chỉ số còn Nháp → không đánh giá (BR-11, J-22)', $r['created'] === [] && ($r['skipped']['Chưa đủ dữ liệu đã xác nhận (incomplete)'] ?? 0) === 1, json_encode($r['skipped'], JSON_UNESCAPED_UNICODE));
// baseline có nguồn đã đổi
$rStale = $rules->create($rule(['name' => 'baseline > 10%', 'threshold_value' => '10']));
$pdo->exec("UPDATE billing_periods SET status = 'mo_lai' WHERE id = " . JUN);
actAs(KT, 'ky_thuat');
$readings->update(1, ['current_reading' => '1350', 'note' => 'sửa thử', 'version_token' => MeterReadingService::versionToken($readings->find(1))]);
actAs(BQL, 'bql');
$r = $eval->evaluatePeriod(JUL, $rStale);
check($G, 'baseline có dữ liệu nguồn đã đổi (kỳ nguồn 06/2024 mở lại + sửa) → 2 công tơ có baseline từ kỳ đó bị bỏ qua, không dùng như dữ liệu chắc chắn', $r['created'] === [] && ($r['skipped']['Baseline có dữ liệu nguồn đã thay đổi — không dùng (NEED BA)'] ?? 0) === 2, json_encode($r['skipped'], JSON_UNESCAPED_UNICODE));
actAs(KT, 'ky_thuat');
$readings->update(1, ['current_reading' => '1343', 'note' => 'trả lại', 'version_token' => MeterReadingService::versionToken($readings->find(1))]);
actAs(BQL, 'bql');
$approval->approve(JUN, [1 => MeterReadingService::versionToken($readings->find(1))]);
$pdo->exec("UPDATE billing_periods SET status = 'da_khoa' WHERE id = " . JUN);
$r = (new AlertEvaluationService())->evaluatePeriod(JUL, $rStale);
check($G, '… nguồn khôi phục → đánh giá lại bình thường (+33,24% > 10%)', count($r['created']) === 1);
// chi phí
$rCost = $rules->create($rule(['name' => 'Tiền điện > 800.000', 'measure_type' => 'chi_phi', 'compare_base' => 'gia_tri_tuyet_doi', 'threshold_value' => '800000']));
$r = $eval->evaluatePeriod(JUN, $rCost);
$ac = alertFor($pdo, $rCost, 0, JUN);
check($G, 'chi phí (kỳ khóa 06/2024, bản tính đã phát hành): BT1 tiền điện 868.698 > 800.000 → 1 cảnh báo cấp căn (không gắn công tơ)', count($r['created']) === 1 && $ac > 0
    && alertRow($pdo, $ac)['meter_id'] === null && alertRow($pdo, $ac)['actual_value'] === '868698.0000');
actAs(KTO, 'ke_toan');
(new BillingCalculationService())->calculateApartment(JUL, 1);
actAs(BQL, 'bql');
$r = $eval->evaluatePeriod(JUL, $rCost);
check($G, 'chi phí kỳ 07 khi bản tính mới Đã tính (chưa xác nhận) → bỏ qua (S5)', $r['created'] === [] && ($r['skipped']['Bản tính chưa xác nhận / CẦN TÍNH LẠI (FN-05)'] ?? 0) === 1);
check($G, 'không có quy tắc "mục tiêu" trong schema — FN-07 không tự coi thiếu mục tiêu = 0 (BLOCKED, chỉ ghi nhận)', !array_key_exists('muc_tieu', config('alerts.compare_base')));
check($G, 'audit tóm tắt EVALUATE_ALERT_RULES', auditCount($pdo, 'EVALUATE_ALERT_RULES') >= 10);

// =========================================================== MANUAL (FN-07.03)
$G = 'MANUAL';
actAs(KT, 'ky_thuat');
$mk = fn (array $o = []) => fn () => $alerts->createManual(array_merge(['apartment_id' => '1', 'meter_id' => '2', 'billing_period_id' => (string) JUL, 'utility_type' => 'nuoc', 'description' => 'Công tơ nước quay khi khóa van', 'priority' => 'cao'], $o));
$man = expectOk($G, 'Kỹ thuật tạo cảnh báo thủ công hợp lệ', $mk());
$mrow = alertRow($pdo, (int) $man['id']);
check($G, '… source=thu_cong, không gắn quy tắc, Mới, created_by = KT, audit CREATE_ALERT_MANUAL', $mrow['source'] === 'thu_cong' && $mrow['alert_rule_id'] === null && $mrow['status'] === 'moi'
    && (int) $mrow['created_by'] === KT && auditCount($pdo, 'CREATE_ALERT_MANUAL', (int) $man['id']) === 1);
$man2 = $alerts->createManual(['apartment_id' => '1', 'meter_id' => '2', 'billing_period_id' => (string) JUL, 'utility_type' => 'nuoc', 'description' => 'lần 2', 'priority' => 'thap']);
check($G, 'thủ công lặp lại vẫn tạo được (không có khóa trùng cho thủ công), có thông báo cảnh báo đang mở', $man2['open_existing'] === 1);
expectError($G, 'căn hộ không tồn tại', 'apartment_id', $mk(['apartment_id' => '999']));
expectError($G, 'công tơ không tồn tại', 'meter_id', $mk(['meter_id' => '999']));
expectError($G, 'công tơ không thuộc căn', 'meter_id', $mk(['meter_id' => '3']), 'không thuộc căn');
expectError($G, 'công tơ khác tiện ích', 'meter_id', $mk(['meter_id' => '1']), 'tiện ích');
actAs(BQL, 'bql');
$deadMeter = (int) (new MeterService())->create(['apartment_id' => '1', 'meter_code' => 'BT1-NUOC-OLD', 'utility_type' => 'nuoc', 'usage_type' => 'sinh_hoat', 'display_label' => 'cũ', 'initial_reading' => '0']);
(new MeterService())->deactivate($deadMeter);
actAs(KT, 'ky_thuat');
expectError($G, 'công tơ đã ngừng', 'meter_id', $mk(['meter_id' => (string) $deadMeter]), 'ngừng');
expectError($G, 'thiếu mô tả → "Vui lòng mô tả hiện tượng bất thường."', 'description', $mk(['description' => '  ']), 'Vui lòng mô tả');
expectError($G, 'kỳ không hợp lệ', 'billing_period_id', $mk(['billing_period_id' => '999']));
expectError($G, 'giá trị sai kiểu (malformed id)', 'apartment_id', $mk(['apartment_id' => '1 OR 1=1']));

// =========================================================== STATE (G2 / AlertStateMachine)
$G = 'STATE';
$all = ['moi', 'da_review', 'can_kiem_tra_lai', 'da_xac_nhan', 'da_dong'];
$expected = ['review' => ['moi'], 'request_verification' => ['da_review'], 'confirm' => ['can_kiem_tra_lai'], 'close' => ['da_review', 'da_xac_nhan'], 'reopen' => ['da_dong']];
$okMatrix = true;
foreach ($expected as $action => $allowed) {
    foreach ($all as $state) {
        $okMatrix = $okMatrix && (AlertStateMachine::can($action, $state) === in_array($state, $allowed, true));
    }
}
check($G, 'ma trận chuyển trạng thái đúng G2 (5 hành động × 5 trạng thái)', $okMatrix);
actAs(BQL, 'bql');
expectError($G, 'Mới → xác nhận: từ chối ở Service', '_', fn () => $vf->confirm($a1, 'confirm', '', tok($a1)));
expectError($G, 'Mới → đóng: từ chối', '_', fn () => $wf->close($a1, 'x', tok($a1)));
expectError($G, 'Mới → mở lại: từ chối', '_', fn () => $wf->reopen($a1, 'x', tok($a1)));
expectError($G, 'Mới → yêu cầu kiểm tra: từ chối', '_', fn () => $vf->request($a1, 'x', (string) KT, tok($a1)));
expectError($G, 'review với token cũ → từ chối (2 người review cùng lúc)', '_', fn () => $wf->review($a1, 'cao', '', 'stale'), 'người khác');
expectOk($G, 'BQL review + đổi ưu tiên Trung bình → Cao', fn () => $wf->review($a1, 'cao', 'Tăng 33% cần xem', tok($a1)));
$row = alertRow($pdo, $a1);
check($G, '… Đã review, reviewed_by/at, lịch sử (Mới → Đã review, ưu tiên), audit REVIEW_ALERT + CHANGE_ALERT_PRIORITY', $row['status'] === 'da_review' && (int) $row['reviewed_by'] === BQL && $row['priority'] === 'cao'
    && (int) q($pdo, "SELECT COUNT(*) FROM alert_status_history WHERE alert_id = ? AND old_status = 'moi' AND new_status = 'da_review' AND new_priority = 'cao'", [$a1]) === 1
    && auditCount($pdo, 'REVIEW_ALERT', $a1) === 1 && auditCount($pdo, 'CHANGE_ALERT_PRIORITY', $a1) === 1);
expectError($G, 'review lần 2 → từ chối', '_', fn () => $wf->review($a1, 'cao', '', tok($a1)));
expectError($G, 'đổi ưu tiên giống cũ → lỗi', 'priority', fn () => $wf->changePriority($a1, 'cao', '', tok($a1)));
expectError($G, 'ưu tiên ngoài ENUM', 'priority', fn () => $wf->changePriority($a1, 'khan_cap', '', tok($a1)));
expectOk($G, 'đổi ưu tiên Cao → Thấp (Đã review)', fn () => $wf->changePriority($a1, 'thap', 'giảm', tok($a1)));

// =========================================================== VERIFICATION (07.07 – 07.09)
$G = 'VERIFY';
expectError($G, 'yêu cầu kiểm tra thiếu nội dung', 'content', fn () => $vf->request($a1, ' ', (string) KT, tok($a1)));
expectError($G, 'giao cho người không phải Kỹ thuật → lỗi', 'assigned_to', fn () => $vf->request($a1, 'Đọc lại công tơ', (string) BQL, tok($a1)));
$case = expectOk($G, 'BQL yêu cầu kiểm tra, giao kythuat01', fn () => $vf->request($a1, 'Đọc lại công tơ điện BT1', (string) KT, tok($a1)));
check($G, '… case Đã giao; cảnh báo Cần kiểm tra lại; audit REQUEST_ALERT_VERIFICATION', q($pdo, 'SELECT status FROM inspection_cases WHERE id = ?', [$case]) === 'da_giao'
    && alertRow($pdo, $a1)['status'] === 'can_kiem_tra_lai' && auditCount($pdo, 'REQUEST_ALERT_VERIFICATION', $a1) === 1);
expectError($G, 'yêu cầu lần 2 → từ chối (trạng thái)', '_', fn () => $vf->request($a1, 'x', (string) KT, tok($a1)));
expectError($G, 'đóng khi đang Cần kiểm tra lại → từ chối', '_', fn () => $wf->close($a1, 'x', tok($a1)));
expectError($G, 'BQL xác nhận khi case chưa có kết quả → "Còn yêu cầu kiểm tra chưa có kết quả."', '_', fn () => $vf->confirm($a1, 'confirm', '', tok($a1)), 'chưa có kết quả');
actAs(KT2, 'ky_thuat');
expectError($G, 'Kỹ thuật KHÁC (không được giao) cập nhật → từ chối', '_', fn () => $vf->submitResult($a1, 'canh_bao_dung', '', ''), 'không giao cho bạn');
check($G, 'Kỹ thuật khác không thấy cảnh báo trong danh sách (K-18)', !$alerts->canView($a1, ['id' => KT2, 'role' => 'ky_thuat']));
actAs(KT, 'ky_thuat');
check($G, 'Kỹ thuật được giao thấy cảnh báo', $alerts->canView($a1, ['id' => KT, 'role' => 'ky_thuat']) && in_array($a1, array_map('intval', array_column($alerts->list(['period_id' => '', 'utility_type' => '', 'zone_id' => '', 'q' => '', 'source' => '', 'priority' => '', 'status' => '', 'rule_id' => ''], ['id' => KT, 'role' => 'ky_thuat']), 'id')), true));
expectError($G, 'thiếu kết luận', 'result_conclusion', fn () => $vf->submitResult($a1, '', '', ''));
expectError($G, 'chỉ số đọc lại âm', 'rechecked_value', fn () => $vf->submitResult($a1, 'canh_bao_dung', '-1', ''));
expectOk($G, 'Kỹ thuật cập nhật kết quả: Cảnh báo đúng', fn () => $vf->submitResult($a1, 'canh_bao_dung', '1800', 'Đọc lại đúng 1800'));
check($G, '… case Đã có kết quả; cảnh báo vẫn Cần kiểm tra lại (KT không tự đóng); audit UPDATE_ALERT_VERIFICATION', q($pdo, 'SELECT status FROM inspection_cases WHERE id = ?', [$case]) === 'da_co_ket_qua'
    && alertRow($pdo, $a1)['status'] === 'can_kiem_tra_lai' && auditCount($pdo, 'UPDATE_ALERT_VERIFICATION', $a1) === 1);
expectError($G, 'cập nhật kết quả lần 2 → từ chối', '_', fn () => $vf->submitResult($a1, 'canh_bao_sai', '', ''));
actAs(BQL, 'bql');
expectOk($G, 'BQL xác nhận dữ liệu đúng (A)', fn () => $vf->confirm($a1, 'confirm', 'Đúng là tiêu thụ tăng', tok($a1)));
check($G, '… Đã xác nhận; case Đã đóng; chỉ số không đổi; audit CONFIRM_ALERT_DATA', alertRow($pdo, $a1)['status'] === 'da_xac_nhan' && q($pdo, 'SELECT status FROM inspection_cases WHERE id = ?', [$case]) === 'da_dong'
    && q($pdo, 'SELECT CONCAT(current_reading, status) FROM meter_readings WHERE id = ?', [$rd[1]]) === '1800.00da_xac_nhan' && auditCount($pdo, 'CONFIRM_ALERT_DATA', $a1) === 1);

// Điều chỉnh (B) - cảnh báo CC2305 SH (rule tuyệt đối), có bản tính FN-05
$a5 = alertFor($pdo, (int) $rAbs, 5);
actAs(KTO, 'ke_toan');
(new BillingCalculationService())->calculateApartment(JUL, 3);
actAs(BQL, 'bql');
$wf->review($a5, 'trung_binh', '', tok($a5));
$vf->request($a5, 'Đọc lại CC2305', (string) KT, tok($a5));
actAs(KT, 'ky_thuat');
$vf->submitResult($a5, 'do_ghi_sai', '590', 'Ghi nhầm 600, thực tế 590');
actAs(BQL, 'bql');
$readTok = MeterReadingService::versionToken($readings->find($rd[5]));
expectError($G, 'điều chỉnh thiếu lý do', 'note', fn () => $vf->confirm($a5, 'adjust', '', tok($a5), ['current_reading' => '590', 'reading_token' => $readTok]));
expectError($G, 'điều chỉnh với token chỉ số cũ → FN-04 từ chối, rollback toàn bộ (cảnh báo/case không đổi)', '_', fn () => $vf->confirm($a5, 'adjust', 'ghi sai', tok($a5), ['current_reading' => '590', 'reading_token' => 'old']));
check($G, '… atomic: cảnh báo vẫn Cần kiểm tra lại, case vẫn Đã có kết quả, chỉ số vẫn 600', alertRow($pdo, $a5)['status'] === 'can_kiem_tra_lai'
    && q($pdo, "SELECT status FROM inspection_cases WHERE alert_id = ? ORDER BY id DESC LIMIT 1", [$a5]) === 'da_co_ket_qua' && q($pdo, 'SELECT current_reading FROM meter_readings WHERE id = ?', [$rd[5]]) === '600.00');
expectError($G, 'CSC mới < CSĐ → FN-04 VAL-04.02 từ chối', 'current_reading', fn () => $vf->confirm($a5, 'adjust', 'ghi sai', tok($a5), ['current_reading' => '100', 'reading_token' => $readTok]));
expectError($G, 'chỉ số mới giống hiện tại → báo dùng "Xác nhận"', 'current_reading', fn () => $vf->confirm($a5, 'adjust', 'ghi sai', tok($a5), ['current_reading' => '600', 'reading_token' => $readTok]));
expectOk($G, 'BQL điều chỉnh (B) CSC 600 → 590 qua MeterReadingService', fn () => $vf->confirm($a5, 'adjust', 'Ghi sai chỉ số theo kết quả kiểm tra', tok($a5), ['current_reading' => '590', 'reading_token' => $readTok]));
$rr = $pdo->query('SELECT * FROM meter_readings WHERE id = ' . $rd[5])->fetch();
check($G, '… chỉ số 590, CL 140 (server tính), Nháp (BR-41), nguồn điều chỉnh, audit UPDATE_METER_READING bởi BQL', $rr['current_reading'] === '590.00' && $rr['consumption'] === '140.00'
    && $rr['status'] === 'nhap' && $rr['source'] === 'dieu_chinh' && (int) q($pdo, "SELECT user_id FROM audit_logs WHERE action = 'UPDATE_METER_READING' AND entity_id = ? ORDER BY id DESC LIMIT 1", [(string) $rd[5]]) === BQL);
check($G, '… cảnh báo Đã xác nhận ghi chú "đã điều chỉnh"', alertRow($pdo, $a5)['status'] === 'da_xac_nhan'
    && str_contains((string) q($pdo, 'SELECT note FROM alert_status_history WHERE alert_id = ? ORDER BY id DESC LIMIT 1', [$a5]), 'Đã điều chỉnh chỉ số'));
$charge = $pdo->query('SELECT * FROM apartment_charges WHERE apartment_id = 3 AND billing_period_id = ' . JUL)->fetch();
check($G, 'FN-05: bản tính CC-2305 thành CẦN TÍNH LẠI (không có "chỉ số mới + tiền cũ" hiện hành)', (new BillingCalculationService())->consistency((new App\Repositories\BillingPeriodRepository())->find(JUL), $charge)['state'] === 'stale_error');
// Kỳ đã khoá: điều chỉnh bị chặn
actAs(KT, 'ky_thuat');
$mj = $alerts->createManual(['apartment_id' => '1', 'meter_id' => '1', 'billing_period_id' => (string) JUN, 'utility_type' => 'dien', 'description' => 'Kiểm tra lại số tháng 6', 'priority' => 'thap'])['id'];
actAs(BQL, 'bql');
$wf->review($mj, 'thap', '', tok($mj));
$vf->request($mj, 'Đọc lại', (string) KT, tok($mj));
actAs(KT, 'ky_thuat');
$vf->submitResult($mj, 'do_ghi_sai', '1340', '');
actAs(BQL, 'bql');
expectError($G, 'kỳ đã khóa: điều chỉnh bị FN-04 chặn (VAL-04.04), không bypass', '_', fn () => $vf->confirm($mj, 'adjust', 'sửa', tok($mj), ['current_reading' => '1340', 'reading_token' => MeterReadingService::versionToken($readings->find(1))]), 'khóa');
check($G, '… kỳ khóa: chỉ số và cảnh báo giữ nguyên', q($pdo, 'SELECT current_reading FROM meter_readings WHERE id = 1') === '1343.00' && alertRow($pdo, $mj)['status'] === 'can_kiem_tra_lai');
expectOk($G, '… vẫn xác nhận dữ liệu đúng (A) được trên kỳ khóa', fn () => $vf->confirm($mj, 'confirm', 'Giữ nguyên', tok($mj)));
expectError($G, 'cảnh báo chi phí (không gắn công tơ) không điều chỉnh được', '_', function () use ($wf, $vf, $ac, $pdo) {
    $wf->review($ac, 'cao', '', tok($ac));
    $vf->request($ac, 'Kiểm tra', (string) KT, tok($ac));
    actAs(KT, 'ky_thuat');
    $vf->submitResult($ac, 'canh_bao_dung', '', '');
    actAs(BQL, 'bql');
    $vf->confirm($ac, 'adjust', 'x', tok($ac), ['current_reading' => '1', 'reading_token' => 'x']);
}, 'không gắn công tơ');

// =========================================================== CLOSE / REOPEN (07.10)
$G = 'CLOSE';
actAs(BQL, 'bql');
expectError($G, 'đóng thiếu lý do', 'close_reason', fn () => $wf->close($a1, ' ', tok($a1)));
expectOk($G, 'đóng từ Đã xác nhận (có lý do)', fn () => $wf->close($a1, 'Đã xử lý, tiêu thụ tăng thật', tok($a1)));
$row = alertRow($pdo, $a1);
check($G, '… Đã đóng, closed_by/at, close_reason, audit CLOSE_ALERT', $row['status'] === 'da_dong' && (int) $row['closed_by'] === BQL && $row['closed_at'] !== null && auditCount($pdo, 'CLOSE_ALERT', $a1) === 1);
expectError($G, 'VAL-07.01: đổi ưu tiên khi đã đóng → "Cảnh báo đã đóng…"', '_', fn () => $wf->changePriority($a1, 'cao', '', tok($a1)), 'đã đóng');
expectError($G, 'yêu cầu kiểm tra khi đã đóng → từ chối', '_', fn () => $vf->request($a1, 'x', (string) KT, tok($a1)), 'đã đóng');
expectError($G, 'mở lại thiếu lý do', 'reopen_reason', fn () => $wf->reopen($a1, '', tok($a1)));
expectOk($G, 'BQL mở lại (có lý do) → Đã review', fn () => $wf->reopen($a1, 'Cư dân phản ánh thêm', tok($a1)));
$row = alertRow($pdo, $a1);
check($G, '… Đã review, reopen_reason, audit REOPEN_ALERT; cảnh báo không bị xóa', $row['status'] === 'da_review' && $row['reopen_reason'] === 'Cư dân phản ánh thêm' && auditCount($pdo, 'REOPEN_ALERT', $a1) === 1);
expectError($G, 'mở lại lần 2 → từ chối', '_', fn () => $wf->reopen($a1, 'x', tok($a1)));
expectOk($G, 'đóng từ Đã review (không cần kiểm tra)', fn () => $wf->close($a1, 'Không cần kiểm tra thêm', tok($a1)));
check($G, 'lịch sử đầy đủ (Mới → … → Đã đóng → Đã review → Đã đóng)', (int) q($pdo, 'SELECT COUNT(*) FROM alert_status_history WHERE alert_id = ?', [$a1]) >= 8);
$mjRow = alertRow($pdo, $mj);
expectOk($G, 'đóng + mở lại cảnh báo của kỳ đã khóa được (cảnh báo độc lập với kỳ)', function () use ($wf, $mj) {
    $wf->close($mj, 'xong', tok($mj));
    $wf->reopen($mj, 'xem lại', tok($mj));
});

// =========================================================== CONCURRENCY
$G = 'CONCURRENCY';
$before = (int) q($pdo, 'SELECT COUNT(*) FROM alerts');
$out = race($testDb, [[BQL, 'bql', 'evaluate', JUL, 0], [BQL, 'bql', 'evaluate', JUL, 0]]);
check($G, '2 worker đánh giá cùng kỳ (mọi quy tắc) → không lỗi, không trùng (UNIQUE + kiểm tra)', $out[0]['ok'] && $out[1]['ok']
    && (int) q($pdo, 'SELECT COUNT(*) FROM (SELECT alert_rule_id, apartment_id, dedupe_meter_id, billing_period_id, COUNT(*) n FROM alerts WHERE alert_rule_id IS NOT NULL GROUP BY 1,2,3,4 HAVING n > 1) d') === 0,
    json_encode($out, JSON_UNESCAPED_UNICODE));
$rNew = $rules->create($rule(['name' => 'nước > 5 m³', 'utility_type' => 'nuoc', 'compare_base' => 'gia_tri_tuyet_doi', 'threshold_value' => '5']));
$out = race($testDb, [[BQL, 'bql', 'evaluate', JUL, $rNew], [KT, 'ky_thuat', 'manual', 1, 2, JUL, 'nuoc'], [BQL, 'bql', 'evaluate', JUL, $rNew]]);
check($G, 'thủ công + 2 đánh giá đồng thời → 1 thủ công + cảnh báo quy tắc không trùng (BT1, S01, CC2305 nước > 5)', $out[1]['ok']
    && (int) q($pdo, 'SELECT COUNT(*) FROM alerts WHERE alert_rule_id = ?', [$rNew]) === 3, json_encode($out, JSON_UNESCAPED_UNICODE));
$b = alertFor($pdo, $rNew, 2);
$t = tok($b);
$out = race($testDb, [[BQL, 'bql', 'review', $b, 'cao', $t], [BQL, 'bql', 'review', $b, 'thap', $t]]);
check($G, '2 BQL review cùng lúc → đúng 1 thành công, 1 lịch sử review', count(array_filter($out, static fn ($o) => $o['ok'])) === 1
    && (int) q($pdo, "SELECT COUNT(*) FROM alert_status_history WHERE alert_id = ? AND new_status = 'da_review'", [$b]) === 1, json_encode($out, JSON_UNESCAPED_UNICODE));
$t = tok($b);
$out = race($testDb, [[BQL, 'bql', 'request', $b, KT, $t], [BQL, 'bql', 'request', $b, KT2, $t]]);
check($G, 'yêu cầu kiểm tra đồng thời → đúng 1 case', count(array_filter($out, static fn ($o) => $o['ok'])) === 1 && (int) q($pdo, 'SELECT COUNT(*) FROM inspection_cases WHERE alert_id = ?', [$b]) === 1, json_encode($out, JSON_UNESCAPED_UNICODE));
$assignee = (int) q($pdo, 'SELECT assigned_to FROM inspection_cases WHERE alert_id = ?', [$b]);
$out = race($testDb, [[$assignee, 'ky_thuat', 'result', $b], [$assignee, 'ky_thuat', 'result', $b]]);
check($G, 'Kỹ thuật gửi kết quả 2 lần đồng thời → đúng 1 thành công', count(array_filter($out, static fn ($o) => $o['ok'])) === 1 && auditCount($pdo, 'UPDATE_ALERT_VERIFICATION', $b) === 1, json_encode($out, JSON_UNESCAPED_UNICODE));
$c2 = alertFor($pdo, $rNew, 4);
$wf->review($c2, 'thap', '', tok($c2));
$t = tok($c2);
$out = race($testDb, [[BQL, 'bql', 'close', $c2, $t], [BQL, 'bql', 'close', $c2, $t]]);
check($G, 'đóng 2 lần đồng thời → đúng 1', count(array_filter($out, static fn ($o) => $o['ok'])) === 1 && auditCount($pdo, 'CLOSE_ALERT', $c2) === 1);
$t = tok($c2);
$out = race($testDb, [[BQL, 'bql', 'reopen', $c2, $t], [BQL, 'bql', 'reopen', $c2, $t]]);
check($G, 'mở lại 2 lần đồng thời → đúng 1; trạng thái cuối Đã review', count(array_filter($out, static fn ($o) => $o['ok'])) === 1 && alertRow($pdo, $c2)['status'] === 'da_review' && auditCount($pdo, 'REOPEN_ALERT', $c2) === 1);

// =========================================================== tổng
$G = 'GENERAL';
check($G, 'không có khuyến nghị nào được sinh (FN-08 chưa làm)', (int) q($pdo, 'SELECT COUNT(*) FROM recommendations') === 0);
check($G, 'không có cảnh báo nào bị xóa; mọi cảnh báo quy tắc có câu giải thích', (int) q($pdo, "SELECT COUNT(*) FROM alerts WHERE source = 'rule' AND (description IS NULL OR description = '')") === 0);
foreach (['CREATE_ALERT_AUTO', 'CREATE_ALERT_MANUAL', 'REVIEW_ALERT', 'CHANGE_ALERT_PRIORITY', 'REQUEST_ALERT_VERIFICATION', 'UPDATE_ALERT_VERIFICATION', 'CONFIRM_ALERT_DATA', 'CLOSE_ALERT', 'REOPEN_ALERT', 'CREATE_ALERT_RULE', 'UPDATE_ALERT_RULE', 'DEACTIVATE_ALERT_RULE'] as $action) {
    check($G, "có audit {$action}", auditCount($pdo, $action) > 0);
}

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
