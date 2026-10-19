<?php

declare(strict_types=1);

/**
 * STEP 12 - Test cấp Service FN-08 (quy tắc khuyến nghị, sinh khuyến nghị gắn cảnh báo, chống trùng, lịch sử, B8, đồng thời).
 * Chạy trên DATABASE TẠM:  php tests/fn08_service_test.php energy_dss_fn08_test
 * Seed: rule khuyến nghị #1 (CL = 0 trong 2 kỳ liên tiếp), #2 (vượt ngưỡng 1 kỳ); mục tiêu BT1 điện 07/2024 = 320.
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
use App\Services\MeterReadingService;
use App\Services\ReadingApprovalService;
use App\Services\RecommendationRuleService;
use App\Services\RecommendationService;

require_once __DIR__ . '/support/legacy_compat.php'; // STEP 4C: chạy nguyên văn trên schema v1 hoặc v2
$pdo = legacy_compat_install();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
$_SERVER['REMOTE_ADDR'] = 'fn08-service-test';
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

function auditCount(PDO $pdo, string $action, ?int $entityId = null): int
{
    return $entityId === null
        ? (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action])
        : (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE action = ? AND entity_id = ?', [$action, (string) $entityId]);
}

function recCount(PDO $pdo, int $alertId, ?int $ruleId = null): int
{
    return $ruleId === null
        ? (int) q($pdo, 'SELECT COUNT(*) FROM recommendations WHERE alert_id = ?', [$alertId])
        : (int) q($pdo, 'SELECT COUNT(*) FROM recommendations WHERE alert_id = ? AND recommendation_rule_id = ?', [$alertId, $ruleId]);
}

function race(string $db, array $jobs): array
{
    $start = microtime(true) + 1.5;
    $procs = [];
    $pipes = [];
    foreach ($jobs as $i => $job) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/fn08_worker.php') . ' ' . escapeshellarg($db) . ' ' . sprintf('%F', $start)
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

$rules = new RecommendationRuleService();
$recs = new RecommendationService();

// =========================================================== FIXTURE: chỉ số 07/2024 đã duyệt + cảnh báo FN-07
$readings = new MeterReadingService();
$approval = new ReadingApprovalService();
foreach ([1 => '1800', 2 => '232', 3 => '800', 4 => '160', 5 => '600', 6 => '74', 7 => '0', 8 => '0'] as $meter => $csc) {
    actAs(KT, 'ky_thuat');
    $r = $readings->create(JUL, $meter, ['current_reading' => $csc, 'note' => '']);
    actAs(BQL, 'bql');
    $approval->approve(JUL, [(int) $r['id'] => MeterReadingService::versionToken($readings->find((int) $r['id']))]);
}
actAs(BQL, 'bql');
$absRule = (new AlertRuleService())->create(['name' => 'Điện > 90 kWh', 'measure_type' => 'tieu_thu', 'utility_type' => 'dien', 'compare_base' => 'gia_tri_tuyet_doi',
    'operator' => 'gt', 'threshold_value' => '90', 'scope_type' => 'toan_toa', 'scope_value' => '', 'priority_level' => 'cao']);
$eval = new AlertEvaluationService();
$eval->evaluatePeriod(JUN, $absRule); // 06/2024: BT1 343, S01 150, CC2305 150 > 90
$eval->evaluatePeriod(JUL, $absRule); // 07/2024: BT1 457, S01 150, CC2305 150 > 90
$aBt1 = (int) q($pdo, 'SELECT id FROM alerts WHERE alert_rule_id = ? AND meter_id = 1 AND billing_period_id = ?', [$absRule, JUL]);
$aS01 = (int) q($pdo, 'SELECT id FROM alerts WHERE alert_rule_id = ? AND meter_id = 3 AND billing_period_id = ?', [$absRule, JUL]);
actAs(KT, 'ky_thuat');
$alerts = new AlertService();
$aCl0 = $alerts->createManual(['apartment_id' => '4', 'meter_id' => '7', 'billing_period_id' => (string) JUL, 'utility_type' => 'dien', 'description' => 'Công tơ điện không quay', 'priority' => 'thap'])['id'];
$aWater = $alerts->createManual(['apartment_id' => '1', 'meter_id' => '2', 'billing_period_id' => (string) JUL, 'utility_type' => 'nuoc', 'description' => 'Kiểm tra công tơ nước', 'priority' => 'thap'])['id'];
$aW8 = $alerts->createManual(['apartment_id' => '4', 'meter_id' => '8', 'billing_period_id' => (string) JUL, 'utility_type' => 'nuoc', 'description' => 'Công tơ nước không quay', 'priority' => 'thap'])['id'];

// =========================================================== RULE (FN-08.01)
$G = 'RULE';
actAs(BQL, 'bql');
$base = ['name' => 'Không đạt mục tiêu', 'condition_type' => 'khong_dat_muc_tieu', 'n_periods' => '', 'utility_type' => '',
    'recommendation_text' => 'Tiêu thụ {utility} kỳ {period} của căn {apartment} chưa đạt mục tiêu — xem xét đối chiếu với kỳ trước.'];
$rTarget = expectOk($G, 'tạo quy tắc hợp lệ (không đạt mục tiêu, có placeholder)', fn () => $rules->create($base));
check($G, '… Bật, audit CREATE_RECOMMENDATION_RULE, tham số NULL', q($pdo, 'SELECT status FROM recommendation_rules WHERE id = ?', [$rTarget]) === 'bat'
    && auditCount($pdo, 'CREATE_RECOMMENDATION_RULE', (int) $rTarget) === 1 && q($pdo, 'SELECT condition_params FROM recommendation_rules WHERE id = ?', [$rTarget]) === null);
$rConsec = expectOk($G, 'tạo quy tắc vượt ngưỡng 2 kỳ liên tiếp, chỉ điện', fn () => $rules->create(['name' => 'Vượt 2 kỳ', 'condition_type' => 'vuot_nguong_lien_tiep', 'n_periods' => '2', 'utility_type' => 'dien',
    'recommendation_text' => 'Tiêu thụ vượt ngưỡng {n_periods} kỳ liên tiếp — xem xét yêu cầu Kỹ thuật kiểm tra hiện trường.']));
check($G, '… tham số JSON {"n_periods":2,"utility_type":"dien"}', json_decode((string) q($pdo, 'SELECT condition_params FROM recommendation_rules WHERE id = ?', [$rConsec]), true) == ['n_periods' => 2, 'utility_type' => 'dien']);
expectError($G, 'thiếu tên', 'name', fn () => $rules->create(array_merge($base, ['name' => ' '])));
expectError($G, 'điều kiện không hợp lệ', 'condition_type', fn () => $rules->create(array_merge($base, ['condition_type' => 'du_bao_ai'])));
expectError($G, 'CL = 0 thiếu N', 'n_periods', fn () => $rules->create(array_merge($base, ['condition_type' => 'cl_zero_n_ky'])));
expectError($G, 'N = 0', 'n_periods', fn () => $rules->create(array_merge($base, ['condition_type' => 'cl_zero_n_ky', 'n_periods' => '0'])));
expectError($G, 'N = 13 (> 12)', 'n_periods', fn () => $rules->create(array_merge($base, ['condition_type' => 'vuot_nguong_lien_tiep', 'n_periods' => '13'])));
expectError($G, 'tiện ích sai', 'utility_type', fn () => $rules->create(array_merge($base, ['utility_type' => 'gas'])));
expectError($G, 'thiếu nội dung', 'recommendation_text', fn () => $rules->create(array_merge($base, ['recommendation_text' => '  '])));
expectError($G, 'nội dung > 500 ký tự', 'recommendation_text', fn () => $rules->create(array_merge($base, ['recommendation_text' => str_repeat('a', 501)])));
$row = $rules->find((int) $rTarget);
expectError($G, 'sửa với token cũ → từ chối', '_', fn () => $rules->update((int) $rTarget, $base + ['version_token' => 'old']), 'người khác');
expectError($G, 'sửa không thay đổi → báo', '_', fn () => $rules->update((int) $rTarget, $base + ['version_token' => $row['version_token']]), 'Không có thay đổi');
expectError($G, 'trạng thái lạ', '_', fn () => $rules->setStatus((int) $rTarget, 'xoa', $row['version_token']));
$tmp = $rules->create(array_merge($base, ['name' => 'tạm']));
expectOk($G, 'Tắt quy tắc (DEACTIVATE_RECOMMENDATION_RULE)', fn () => $rules->setStatus($tmp, 'tat', $rules->find($tmp)['version_token']));
expectOk($G, 'Bật lại (ACTIVATE_RECOMMENDATION_RULE)', fn () => $rules->setStatus($tmp, 'bat', $rules->find($tmp)['version_token']));
expectError($G, 'Bật khi đang Bật → báo', '_', fn () => $rules->setStatus($tmp, 'bat', $rules->find($tmp)['version_token']), 'đã ở trạng thái');
$rules->setStatus($tmp, 'tat', $rules->find($tmp)['version_token']);

// =========================================================== GENERATION (FN-08.02)
$G = 'GENERATE';
$beforeAlerts = (int) q($pdo, 'SELECT COUNT(*) FROM alerts');
$beforeStatus = q($pdo, 'SELECT CONCAT(status, priority) FROM alerts WHERE id = ?', [$aBt1]);
expectError($G, 'cảnh báo không tồn tại → lỗi', '_', fn () => $recs->generateForAlert(999999), 'không tồn tại');
$r = $recs->generateForAlert($aBt1);
check($G, 'cảnh báo tự động BT1 (457 kWh): nhiều quy tắc khớp → 3 khuyến nghị (#2 vượt 1 kỳ, vượt 2 kỳ liên tiếp, không đạt mục tiêu 320)', count($r['created']) === 3
    && recCount($pdo, $aBt1, 2) === 1 && recCount($pdo, $aBt1, (int) $rConsec) === 1 && recCount($pdo, $aBt1, (int) $rTarget) === 1, json_encode($r, JSON_UNESCAPED_UNICODE));
check($G, '… quy tắc CL = 0 không khớp (CL ≠ 0), quy tắc đang Tắt không được đánh giá', isset($r['not_matched'][1]) && !isset($r['not_matched'][$tmp]) && recCount($pdo, $aBt1, 1) === 0);
$text = (string) q($pdo, 'SELECT recommendation_text FROM recommendations WHERE alert_id = ? AND recommendation_rule_id = ?', [$aBt1, $rTarget]);
check($G, 'nội dung từ quy tắc DB, placeholder thay bằng giá trị thuần (không sinh câu mới)', $text === 'Tiêu thụ điện kỳ 07/2024 của căn BT1 chưa đạt mục tiêu — xem xét đối chiếu với kỳ trước.', $text);
check($G, 'không tự thực hiện hành động: trạng thái/ưu tiên cảnh báo giữ nguyên, không có case, không tạo cảnh báo', q($pdo, 'SELECT CONCAT(status, priority) FROM alerts WHERE id = ?', [$aBt1]) === $beforeStatus
    && (int) q($pdo, 'SELECT COUNT(*) FROM inspection_cases') === 0 && (int) q($pdo, 'SELECT COUNT(*) FROM alerts') === $beforeAlerts);
$log = json_decode((string) q($pdo, "SELECT new_value FROM audit_logs WHERE action = 'GENERATE_RECOMMENDATIONS' AND entity_id = ? ORDER BY id DESC LIMIT 1", [(string) $aBt1]), true);
check($G, 'audit GENERATE_RECOMMENDATIONS: quy tắc lúc sinh + lý do + bằng chứng (06 & 07 vượt ngưỡng; mục tiêu 320 / thực tế 457)', count($log['created'] ?? []) === 3
    && str_contains(json_encode($log, JSON_UNESCAPED_UNICODE), '"exceeded":true') && str_contains(json_encode($log, JSON_UNESCAPED_UNICODE), '"target":"320.00"'));
$r = $recs->generateForAlert($aBt1);
check($G, 'sinh lại → 0 mới, 3 đã có (không trùng cảnh báo + quy tắc)', $r['created'] === [] && $r['duplicates'] === 3 && recCount($pdo, $aBt1) === 3);
$r = $recs->generateForAlert($aCl0);
check($G, 'cảnh báo thủ công CC2306 điện, CL = 0 ở 06 và 07/2024 → khuyến nghị "Kiểm tra công tơ/căn hộ trống" (#1)', $r['created'] !== [] && recCount($pdo, $aCl0, 1) === 1
    && q($pdo, 'SELECT recommendation_text FROM recommendations WHERE alert_id = ? AND recommendation_rule_id = 1', [$aCl0]) === 'Kiểm tra công tơ/căn hộ trống');
check($G, '… cảnh báo thủ công không khớp quy tắc vượt ngưỡng (không có quy tắc ngưỡng)', recCount($pdo, $aCl0, 2) === 0 && str_contains($r['not_matched'][2] ?? '', 'thủ công'));
$r = $recs->generateForAlert($aWater);
check($G, '0 quy tắc khớp (nước BT1 CL 16, không mục tiêu nước, quy tắc 2 kỳ chỉ điện) → không tạo khuyến nghị (FR-10 Negative)', $r['created'] === [] && recCount($pdo, $aWater) === 0
    && str_contains($r['not_matched'][(int) $rConsec] ?? '', 'chỉ áp cho điện'), json_encode($r['not_matched'], JSON_UNESCAPED_UNICODE));
$r = $recs->generateForAlert($aS01);
check($G, 'S01 (KD 150): vượt 1 kỳ + vượt 2 kỳ liên tiếp; không có mục tiêu → không coi là không đạt', recCount($pdo, $aS01, 2) === 1 && recCount($pdo, $aS01, (int) $rConsec) === 1 && recCount($pdo, $aS01, (int) $rTarget) === 0);
actAs(BQL, 'bql');
$rules->setStatus(1, 'tat', $rules->find(1)['version_token']);
$r = $recs->generateForAlert($aW8);
check($G, 'quy tắc #1 đã Tắt → không sinh cho cảnh báo CL = 0 khác (nước CC2306)', recCount($pdo, $aW8, 1) === 0);
$rules->setStatus(1, 'bat', $rules->find(1)['version_token']);
$r = $recs->generateForAlert($aW8);
check($G, '… bật lại → sinh (CL nước = 0 hai kỳ)', recCount($pdo, $aW8, 1) === 1);
$wf = new AlertWorkflowService();
$tok = AlertService::versionToken($pdo->query('SELECT * FROM alerts WHERE id = ' . $aCl0)->fetch());
$wf->review($aCl0, 'thap', '', $tok);
$tok = AlertService::versionToken($pdo->query('SELECT * FROM alerts WHERE id = ' . $aCl0)->fetch());
$wf->close($aCl0, 'Căn trống, đã xác nhận', $tok);
expectError($G, 'cảnh báo đã đóng → không sinh mới', '_', fn () => $recs->generateForAlert($aCl0), 'đã đóng');
check($G, '… khuyến nghị lịch sử của cảnh báo đã đóng vẫn xem được', count($recs->forAlert($aCl0)) === 1);
// trace
$t = $pdo->query("SELECT x.id, x.recommendation_rule_id, al.alert_rule_id, al.meter_id, al.billing_period_id,
        (SELECT COUNT(*) FROM meter_readings r WHERE r.meter_id = al.meter_id AND r.billing_period_id = al.billing_period_id) AS readings
    FROM recommendations x JOIN alerts al ON al.id = x.alert_id WHERE x.alert_id = {$aBt1}")->fetchAll();
check($G, 'truy vết: khuyến nghị → quy tắc khuyến nghị → cảnh báo → quy tắc cảnh báo → chỉ số công tơ của kỳ', count($t) === 3
    && array_filter($t, static fn ($x) => (int) $x['alert_rule_id'] === (int) $absRule && (int) $x['readings'] === 1) === $t);

// =========================================================== B8 / J-26: CL = 0 KHÔNG có cảnh báo
$G = 'B8_CL0';
check($G, 'CC2306 nước: CL = 0 ở 06 & 07 nhưng (trước khi có cảnh báo thủ công) không có cảnh báo nào → không có đường sinh khuyến nghị độc lập', !method_exists($recs, 'generateForPeriod'));
check($G, 'không tạo cảnh báo giả để vượt ràng buộc alert_id NOT NULL (mọi cảnh báo đều do quy tắc hoặc người tạo)', (int) q($pdo, 'SELECT COUNT(*) FROM alerts WHERE source = \'rule\' AND alert_rule_id IS NULL') === 0
    && (int) q($pdo, "SELECT COUNT(*) FROM alerts WHERE source = 'thu_cong' AND created_by IS NULL") === 0);
check($G, 'mọi khuyến nghị gắn cảnh báo cùng căn/công tơ/kỳ với dữ liệu khớp (không gắn cảnh báo không liên quan)', (int) q($pdo, 'SELECT COUNT(*) FROM recommendations x LEFT JOIN alerts al ON al.id = x.alert_id WHERE al.id IS NULL') === 0);

// =========================================================== HISTORY
$G = 'HISTORY';
$old = (string) q($pdo, 'SELECT recommendation_text FROM recommendations WHERE alert_id = ? AND recommendation_rule_id = 2', [$aBt1]);
$rule2 = $rules->find(2);
expectOk($G, 'sửa nội dung quy tắc #2 sau khi đã sinh', fn () => $rules->update(2, ['name' => $rule2['name'], 'condition_type' => $rule2['condition_type'], 'n_periods' => '1', 'utility_type' => '',
    'recommendation_text' => 'Nội dung mới: đối chiếu lại chỉ số kỳ hiện tại với kỳ trước.', 'version_token' => $rule2['version_token']]));
check($G, 'khuyến nghị cũ giữ nguyên nội dung lúc sinh (snapshot recommendations.recommendation_text)', (string) q($pdo, 'SELECT recommendation_text FROM recommendations WHERE alert_id = ? AND recommendation_rule_id = 2', [$aBt1]) === $old);
$list = $recs->forAlert($aBt1);
$one = array_values(array_filter($list, static fn ($x) => (int) $x['recommendation_rule_id'] === 2))[0];
check($G, '… màn hình phân biệt: nội dung lúc sinh vs nội dung quy tắc hiện tại (rule_changed)', $one['rule_changed'] === true && $one['recommendation_text'] === $old && $one['why']['rule']['recommendation_text'] === $old);
check($G, 'sinh lại sau khi sửa quy tắc → không tạo bản mới (không sinh lại tùy tiện)', $recs->generateForAlert($aBt1)['created'] === [] && recCount($pdo, $aBt1, 2) === 1);
check($G, 'quy tắc đã dùng không xóa được (FK RESTRICT) — chỉ Tắt', (function () use ($pdo) {
    try {
        $pdo->exec('DELETE FROM recommendation_rules WHERE id = 2');

        return false;
    } catch (PDOException $e) {
        return (int) ($e->errorInfo[1] ?? 0) === 1451;
    }
})());

// =========================================================== CONCURRENCY
$G = 'CONCURRENCY';
actAs(KT, 'ky_thuat');
$aRace = $alerts->createManual(['apartment_id' => '4', 'meter_id' => '7', 'billing_period_id' => (string) JUL, 'utility_type' => 'dien', 'description' => 'race', 'priority' => 'thap'])['id'];
foreach ([1, 2] as $round) {
    $out = race($testDb, [[BQL, 'bql', 'generate', $aRace], [BQL, 'bql', 'generate', $aRace], [BQL, 'bql', 'generate', $aRace]]);
    check($G, "lần {$round}: 3 worker sinh cùng cảnh báo → không trùng (cảnh báo, quy tắc)", !in_array(false, array_column($out, 'ok'), true)
        && (int) q($pdo, 'SELECT COUNT(*) FROM (SELECT alert_id, recommendation_rule_id, COUNT(*) n FROM recommendations GROUP BY 1, 2 HAVING n > 1) d') === 0
        && recCount($pdo, $aRace, 1) === 1, json_encode($out, JSON_UNESCAPED_UNICODE));
}
$pdo->beginTransaction();
$blocked = false;
try {
    $pdo->exec("INSERT INTO recommendations (alert_id, recommendation_rule_id, recommendation_text) SELECT alert_id, recommendation_rule_id, recommendation_text FROM recommendations WHERE alert_id = {$aRace} LIMIT 1");
} catch (PDOException $e) {
    $blocked = (int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_rec_alert_rule');
}
$pdo->rollBack();
check($G, 'D-1 đã sửa (patch 002): INSERT trực tiếp trùng (cảnh báo, quy tắc) ngoài Service → DB chặn 1062 uq_rec_alert_rule; rollback giữ nguyên 1 bản', $blocked && recCount($pdo, $aRace, 1) === 1);
actAs(BQL, 'bql');
$t = $rules->find($tmp)['version_token'];
$out = race($testDb, [[BQL, 'bql', 'status', $tmp, 'bat', $t], [BQL, 'bql', 'status', $tmp, 'bat', $t]]);
check($G, '2 BQL bật cùng quy tắc → đúng 1 thành công', count(array_filter($out, static fn ($o) => $o['ok'])) === 1 && auditCount($pdo, 'ACTIVATE_RECOMMENDATION_RULE', $tmp) === 2, json_encode($out, JSON_UNESCAPED_UNICODE));
$t = $rules->find($tmp)['version_token'];
$out = race($testDb, [[BQL, 'bql', 'update', $tmp, 'Nội dung A', $t], [BQL, 'bql', 'update', $tmp, 'Nội dung B', $t]]);
$final = (string) q($pdo, 'SELECT recommendation_text FROM recommendation_rules WHERE id = ?', [$tmp]);
check($G, '2 BQL sửa cùng quy tắc (cùng token) → 1 thành công, 1 bị từ chối (không ghi đè âm thầm)', count(array_filter($out, static fn ($o) => $o['ok'])) === 1
    && in_array($final, ['Nội dung A', 'Nội dung B'], true), json_encode($out, JSON_UNESCAPED_UNICODE));

// =========================================================== GENERAL
$G = 'GENERAL';
check($G, 'không sinh cảnh báo / case / sửa chỉ số / tính phí từ FN-08 (chỉ ghi recommendations + audit)', (int) q($pdo, 'SELECT COUNT(*) FROM inspection_cases') === 0
    && (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ?', [JUL]) === 0
    && (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE action IN ('UPDATE_METER_READING','CALCULATE_APARTMENT_CHARGE','LOCK_BILLING_PERIOD','REQUEST_ALERT_VERIFICATION')") === 0);
foreach (['CREATE_RECOMMENDATION_RULE', 'UPDATE_RECOMMENDATION_RULE', 'ACTIVATE_RECOMMENDATION_RULE', 'DEACTIVATE_RECOMMENDATION_RULE', 'GENERATE_RECOMMENDATIONS'] as $action) {
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
