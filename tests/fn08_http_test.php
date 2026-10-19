<?php

declare(strict_types=1);

/**
 * STEP 12 - Test HTTP FN-08 qua Apache XAMPP THẬT (APP_URL) trên DB của .env (energy_dss).
 *   php tests/fn08_http_test.php
 * Luồng: kỳ 2093 + chỉ số đã duyệt → rule ngưỡng → cảnh báo (FN-07) → BQL cấu hình quy tắc khuyến nghị → sinh khuyến nghị
 * → chi tiết cảnh báo hiển thị (escape, "tham khảo", "vì sao") → sửa / tắt quy tắc (lịch sử giữ nguyên).
 * RBAC / CSRF / input sai kiểu / không tự thao tác / audit / app.log. XOÁ dữ liệu E2E ở cuối (audit giữ lại).
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/real_db_fixture.php'; // fixture riêng: không phụ thuộc / không sửa dữ liệu thật
restore_error_handler();
restore_exception_handler();

use App\Core\Database;

$base = rtrim((string) config('app.url'), '/');
$pdo = Database::connection();
$runId = date('His') . random_int(10, 99);
$month = random_int(1, 12);
$jar = static fn (string $who): string => sys_get_temp_dir() . "/fn08_{$who}_{$runId}.txt";
$logFile = BASE_PATH . '/storage/logs/app.log';
clearstatcache();
$logBefore = filesize($logFile);
$seedRecRules = $pdo->query('SELECT GROUP_CONCAT(CONCAT(id, status, recommendation_text) ORDER BY id) FROM recommendation_rules WHERE name IN ("CL=0 trong 2 kỳ liên tiếp", "Tiêu thụ tăng bất thường")')->fetchColumn();

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

function http(string $jar, string $method, string $url, $post = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post) ? $post : http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

    return ['code' => $code, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $size)];
}

function csrfFrom(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

function tokenFrom(string $html): string
{
    preg_match('/name="version_token" value="([0-9a-f]{40})"/', $html, $m);

    return $m[1] ?? '';
}

function login(string $base, string $jar, string $user): bool
{
    @unlink($jar);
    $page = http($jar, 'GET', $base . '/login');
    $r = http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => $user, 'password' => 'Password@123']);

    return $r['code'] === 302 && str_ends_with($r['location'], '/dashboard');
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

$periodId = 0;
$fx = e2e_fixture_create($pdo, $runId);
$M = $fx['meter'];
$A = $fx['apt'];
try {
    foreach (['bql' => 'bql01', 'kt' => 'kythuat01', 'kto' => 'ketoan01'] as $who => $user) {
        check('LOGIN', "Đăng nhập {$user}", login($base, $jar($who), $user));
    }

    // ================================================================ chuẩn bị: kỳ 2093, chỉ số đã duyệt, cảnh báo FN-07
    $form = http($jar('bql'), 'GET', $base . '/periods/create');
    $r = http($jar('bql'), 'POST', $base . '/periods', ['csrf_token' => csrfFrom($form['body']), 'month' => (string) $month, 'year' => '2093']);
    $periodId = preg_match('#/periods/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
    $fx['periods'][] = $periodId;
    $page = http($jar('kt'), 'GET', $base . "/periods/{$periodId}/readings/create?meter_id={$M[1]}");
    http($jar('kt'), 'POST', $base . "/periods/{$periodId}/readings", ['csrf_token' => csrfFrom($page['body']), 'meter_id' => (string) $M[1], 'current_reading' => '1686', 'note' => "E2E {$runId}"]);
    $ap = http($jar('bql'), 'GET', $base . "/periods/{$periodId}/approvals");
    preg_match('/name="token_(\d+)" value="([0-9a-f]{40})"/', $ap['body'], $tm);
    http($jar('bql'), 'POST', $base . "/periods/{$periodId}/approvals", 'csrf_token=' . urlencode(csrfFrom($ap['body'])) . '&reading_ids%5B%5D=' . ($tm[1] ?? 0) . '&token_' . ($tm[1] ?? 0) . '=' . ($tm[2] ?? ''));
    $rf = http($jar('bql'), 'GET', $base . '/alert-rules/create');
    $r = http($jar('bql'), 'POST', $base . '/alert-rules', ['csrf_token' => csrfFrom($rf['body']), 'name' => "E2E {$runId}", 'measure_type' => 'tieu_thu', 'utility_type' => 'dien', 'compare_base' => 'gia_tri_tuyet_doi',
        'operator' => 'gt', 'threshold_value' => '299.' . random_int(1, 9), 'scope_type' => 'usage_type', 'scope_usage' => 'sinh_hoat', 'priority_level' => 'cao']);
    $al = http($jar('bql'), 'GET', $base . '/alerts');
    http($jar('bql'), 'POST', $base . '/alerts/evaluate', ['csrf_token' => csrfFrom($al['body']), 'period_id' => (string) $periodId]);
    $alertId = (int) q($pdo, 'SELECT id FROM alerts WHERE billing_period_id = ? AND meter_id = ? AND alert_rule_id IN (SELECT id FROM alert_rules WHERE name LIKE ?)', [$periodId, $M[1], e2e_rule_like($runId)]);
    check('SETUP', 'Kỳ 2093: chỉ số đã duyệt → quy tắc ngưỡng → cảnh báo tự động (FN-07)', $periodId > 0 && $alertId > 0);

    // ================================================================ BQL cấu hình quy tắc khuyến nghị
    $list = http($jar('bql'), 'GET', $base . '/recommendation-rules');
    check('RULE', 'GET /recommendation-rules (BQL) 200: 2 quy tắc seed (FR-10) + sidebar', $list['code'] === 200 && str_contains($list['body'], 'Kiểm tra công tơ/căn hộ trống') && str_contains($list['body'], 'Quy tắc khuyến nghị'));
    $cf = http($jar('bql'), 'GET', $base . '/recommendation-rules/create');
    $csrf = csrfFrom($cf['body']);
    http($jar('bql'), 'POST', $base . '/recommendation-rules', ['csrf_token' => $csrf, 'name' => "E2E {$runId}", 'condition_type' => 'vuot_nguong_lien_tiep', 'n_periods' => '1', 'recommendation_text' => '']);
    check('RULE', 'Thiếu nội dung → lỗi trên form, không lưu', str_contains(http($jar('bql'), 'GET', $base . '/recommendation-rules/create')['body'], 'Vui lòng nhập nội dung khuyến nghị.') && (int) q($pdo, 'SELECT COUNT(*) FROM recommendation_rules WHERE name LIKE ?', [e2e_rule_like($runId)]) === 0);
    $r = http($jar('bql'), 'POST', $base . '/recommendation-rules', 'csrf_token=' . urlencode($csrf) . '&name[]=x&condition_type[]=cl_zero_n_ky&recommendation_text[]=y');
    check('ROBUST', 'POST quy tắc với mảng → lỗi validation, không 500', $r['code'] === 302 && (int) q($pdo, 'SELECT COUNT(*) FROM recommendation_rules WHERE name LIKE ?', [e2e_rule_like($runId)]) === 0);
    $xss = '<script>alert(1)</script> Đối chiếu lại chỉ số kỳ {period} của công tơ {meter} với kỳ trước.';
    $r = http($jar('bql'), 'POST', $base . '/recommendation-rules', ['csrf_token' => $csrf, 'name' => "E2E {$runId}", 'condition_type' => 'vuot_nguong_lien_tiep', 'n_periods' => '1', 'utility_type' => 'dien', 'recommendation_text' => $xss]);
    $recRuleId = preg_match('#/recommendation-rules/(\d+)$#', $r['location'], $m) ? (int) $m[1] : 0;
    check('RULE', 'BQL tạo quy tắc "vượt ngưỡng 1 kỳ, chỉ điện" (Bật)', $recRuleId > 0 && q($pdo, 'SELECT name FROM recommendation_rules WHERE id = ?', [$recRuleId]) === "E2E {$runId}" && q($pdo, 'SELECT status FROM recommendation_rules WHERE id = ?', [$recRuleId]) === 'bat');
    $show = http($jar('bql'), 'GET', $base . '/recommendation-rules/' . $recRuleId);
    check('SECURITY', 'Nội dung có <script> được escape trên trang quy tắc', str_contains($show['body'], '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($show['body'], '<script>alert(1)</script>'));
    $r = http($jar('bql'), 'POST', $base . "/recommendation-rules/{$recRuleId}/status", ['csrf_token' => 'invalid', 'status' => 'tat', 'version_token' => tokenFrom($show['body'])]);
    check('CSRF', 'POST tắt quy tắc với CSRF sai → 403, không đổi', $r['code'] === 403 && q($pdo, 'SELECT status FROM recommendation_rules WHERE id = ?', [$recRuleId]) === 'bat');

    // ================================================================ sinh & hiển thị
    $statusBefore = q($pdo, 'SELECT CONCAT(status, priority, updated_at) FROM alerts WHERE id = ?', [$alertId]);
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    check('DETAIL', 'Chi tiết cảnh báo có mục "Khuyến nghị hỗ trợ quyết định" + nút Sinh khuyến nghị (thay placeholder)', str_contains($d['body'], 'Khuyến nghị hỗ trợ quyết định (FN-08)') && str_contains($d['body'], 'Sinh khuyến nghị'));
    $r = http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/recommendations", ['csrf_token' => csrfFrom($d['body'])]);
    $n = (int) q($pdo, 'SELECT COUNT(*) FROM recommendations WHERE alert_id = ?', [$alertId]);
    check('GENERATE', 'BQL bấm "Sinh khuyến nghị" → khuyến nghị theo quy tắc khớp (seed #2 + quy tắc E2E)', $r['code'] === 302 && $n === 2
        && (int) q($pdo, 'SELECT COUNT(*) FROM recommendations WHERE alert_id = ? AND recommendation_rule_id = ?', [$alertId, $recRuleId]) === 1);
    check('GENERATE', 'Không tự thao tác: trạng thái / ưu tiên / updated_at cảnh báo giữ nguyên, không có case', q($pdo, 'SELECT CONCAT(status, priority, updated_at) FROM alerts WHERE id = ?', [$alertId]) === $statusBefore
        && (int) q($pdo, 'SELECT COUNT(*) FROM inspection_cases WHERE alert_id = ?', [$alertId]) === 0);
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/recommendations", ['csrf_token' => csrfFrom($d['body'])]);
    check('GENERATE', 'Sinh lại → không trùng', (int) q($pdo, 'SELECT COUNT(*) FROM recommendations WHERE alert_id = ?', [$alertId]) === 2);
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    check('DETAIL', 'Hiển thị: "Khuyến nghị tham khảo", ghi chú người phụ trách quyết định, quy tắc nguồn, thời điểm, "Vì sao"', str_contains($d['body'], 'Khuyến nghị tham khảo.')
        && str_contains($d['body'], 'người phụ trách quyết định hành động xử lý') && str_contains($d['body'], 'Vì sao: điều kiện') && str_contains($d['body'], 'sinh lúc'));
    $code = (string) q($pdo, 'SELECT period_code FROM billing_periods WHERE id = ?', [$periodId]);
    check('SECURITY', 'Nội dung khuyến nghị: placeholder thay giá trị, <script> escape', str_contains($d['body'], '&lt;script&gt;alert(1)&lt;/script&gt; Đối chiếu lại chỉ số kỳ ' . $code . ' của công tơ ' . $fx['meter_code'][1]) && !str_contains($d['body'], '<script>alert(1)</script>'));

    // lịch sử: sửa quy tắc sau khi sinh
    $edit = http($jar('bql'), 'GET', $base . "/recommendation-rules/{$recRuleId}/edit");
    http($jar('bql'), 'POST', $base . "/recommendation-rules/{$recRuleId}/update", ['csrf_token' => csrfFrom($edit['body']), 'version_token' => tokenFrom($edit['body']), 'name' => "E2E {$runId}",
        'condition_type' => 'vuot_nguong_lien_tiep', 'n_periods' => '1', 'utility_type' => 'dien', 'recommendation_text' => 'Nội dung mới sau khi sửa.']);
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    check('HISTORY', 'Sửa quy tắc: khuyến nghị cũ giữ nội dung lúc sinh + ghi chú "nội dung quy tắc đã sửa"', str_contains($d['body'], 'Đối chiếu lại chỉ số kỳ ' . $code) && !str_contains($d['body'], 'Nội dung mới sau khi sửa.')
        && str_contains($d['body'], 'nội dung quy tắc đã sửa sau khi sinh'));
    $show = http($jar('bql'), 'GET', $base . '/recommendation-rules/' . $recRuleId);
    http($jar('bql'), 'POST', $base . "/recommendation-rules/{$recRuleId}/status", ['csrf_token' => csrfFrom($show['body']), 'status' => 'tat', 'version_token' => tokenFrom($show['body'])]);
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    check('HISTORY', 'Tắt quy tắc: khuyến nghị đã sinh vẫn hiển thị kèm "quy tắc hiện đang Tắt"', q($pdo, 'SELECT status FROM recommendation_rules WHERE id = ?', [$recRuleId]) === 'tat' && str_contains($d['body'], 'quy tắc hiện đang Tắt'));

    // 0 khớp: cảnh báo thủ công của KT
    $mf = http($jar('kt'), 'GET', $base . '/alerts/create');
    $r = http($jar('kt'), 'POST', $base . '/alerts', ['csrf_token' => csrfFrom($mf['body']), 'apartment_id' => (string) $A['BT1'], 'meter_id' => (string) $M[1], 'billing_period_id' => (string) $periodId, 'utility_type' => 'dien', 'description' => 'Kiểm tra thêm', 'priority' => 'thap']);
    $manualId = preg_match('#/alerts/(\d+)$#', $r['location'], $m) ? (int) $m[1] : 0;
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $manualId);
    http($jar('bql'), 'POST', $base . "/alerts/{$manualId}/recommendations", ['csrf_token' => csrfFrom($d['body'])]);
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $manualId);
    check('GENERATE', 'Cảnh báo thủ công không khớp quy tắc → không bịa khuyến nghị, báo "không có quy tắc nào thỏa"', (int) q($pdo, 'SELECT COUNT(*) FROM recommendations WHERE alert_id = ?', [$manualId]) === 0
        && str_contains($d['body'], 'Không có quy tắc khuyến nghị nào thỏa điều kiện'));

    // ================================================================ RBAC
    $kd = http($jar('kt'), 'GET', $base . '/alerts/' . $manualId);
    check('RBAC', 'Kỹ thuật xem cảnh báo của mình nhưng KHÔNG thấy mục khuyến nghị (quyền chưa có nguồn — NEED BA)', $kd['code'] === 200 && !str_contains($kd['body'], 'Khuyến nghị hỗ trợ quyết định'));
    $kod = http($jar('kto'), 'GET', $base . '/alerts/' . $alertId);
    check('RBAC', 'Kế toán xem chi tiết cảnh báo nhưng không thấy mục khuyến nghị ([SL] — NEED BA)', $kod['code'] === 200 && !str_contains($kod['body'], 'Khuyến nghị hỗ trợ quyết định'));
    check('RBAC', 'Kỹ thuật / Kế toán GET /recommendation-rules → 403', http($jar('kt'), 'GET', $base . '/recommendation-rules')['code'] === 403 && http($jar('kto'), 'GET', $base . '/recommendation-rules')['code'] === 403);
    check('RBAC', 'Kế toán POST sinh khuyến nghị (URL trực tiếp) → 403', http($jar('kto'), 'POST', $base . "/alerts/{$alertId}/recommendations", ['csrf_token' => 'x'])['code'] === 403);
    check('RBAC', 'Kỹ thuật POST tạo quy tắc → 403', http($jar('kt'), 'POST', $base . '/recommendation-rules', ['csrf_token' => 'x'])['code'] === 403);
    check('RBAC', 'Chỉ 3 vai trò: admin01 không đăng nhập được (J-01 đã chốt)', !login($base, $jar('adm'), 'admin01'));
    check('ROBUST', 'POST sinh khuyến nghị cho cảnh báo không tồn tại → báo lỗi, không 500', http($jar('bql'), 'POST', $base . '/alerts/99999999/recommendations', ['csrf_token' => csrfFrom($d['body'])])['code'] === 302);
    check('ROBUST', 'GET /recommendation-rules/abc → 404', http($jar('bql'), 'GET', $base . '/recommendation-rules/abc')['code'] === 404);

    // ================================================================ Audit + log
    $seen = $pdo->query("SELECT action, COUNT(*) FROM audit_logs WHERE (entity_type = 'recommendation_rule' AND entity_id = '{$recRuleId}') OR (entity_type = 'alert' AND entity_id IN ('{$alertId}', '{$manualId}') AND action = 'GENERATE_RECOMMENDATIONS') GROUP BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
    check('AUDIT', 'Audit CREATE / UPDATE / DEACTIVATE_RECOMMENDATION_RULE, GENERATE_RECOMMENDATIONS', ($seen['CREATE_RECOMMENDATION_RULE'] ?? 0) == 1 && ($seen['UPDATE_RECOMMENDATION_RULE'] ?? 0) == 1
        && ($seen['DEACTIVATE_RECOMMENDATION_RULE'] ?? 0) == 1 && ($seen['GENERATE_RECOMMENDATIONS'] ?? 0) == 3, json_encode($seen));
    clearstatcache();
    $newLog = (string) file_get_contents($logFile, false, null, $logBefore);
    check('LOG', 'app.log: không có lỗi PHP / Uncaught (CSRF sai ghi [SECURITY])', !preg_match('/PHP Error|Fatal Error|Uncaught/i', $newLog) && str_contains($newLog, '[SECURITY] CSRF token rejected'));
} finally {
    e2e_cleanup($pdo, $fx); // kỳ test + fixture (+ cảnh báo, khuyến nghị, case gắn vào chúng)
    e2e_rules_cleanup($pdo, $runId); // quy tắc cảnh báo / khuyến nghị "E2E <runId>" (+ cảnh báo, khuyến nghị của chúng) - không đụng dữ liệu người dùng
    // chỉ đếm dữ liệu thuộc phạm vi test (id fixture / id kỳ test / mã lượt chạy) - không đếm cảnh báo / khuyến nghị của người dùng
    $left = e2e_leftovers($pdo, $fx) + e2e_rules_leftovers($pdo, $runId);
    check('CLEANUP', 'Đã xóa dữ liệu E2E (kỳ test 2093, fixture, quy tắc, cảnh báo, khuyến nghị); quy tắc seed không đổi (audit giữ lại)', $left === 0
        && $pdo->query('SELECT GROUP_CONCAT(CONCAT(id, status, recommendation_text) ORDER BY id) FROM recommendation_rules WHERE name IN ("CL=0 trong 2 kỳ liên tiếp", "Tiêu thụ tăng bất thường")')->fetchColumn() === $seedRecRules, "còn {$left}");
    foreach (['bql', 'kt', 'kto', 'adm'] as $who) {
        @unlink($jar($who));
    }
}

$failed = 0;
foreach ($results as [$g, $n, $ok, $d]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-9s %s%s\n", $ok ? 'PASS' : 'FAIL', $g, $n, $ok || $d === '' ? '' : "  -> {$d}");
}
printf("\n%d/%d PASS (HTTP test qua %s, run %s)\n", count($results) - $failed, count($results), $base, $runId);
exit($failed === 0 ? 0 : 1);
