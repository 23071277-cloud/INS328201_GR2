<?php

declare(strict_types=1);

/**
 * STEP 11 - Test HTTP FN-07 qua Apache XAMPP THẬT (APP_URL) trên DB của .env (energy_dss).
 *   php tests/fn07_http_test.php
 * Luồng: KT nhập chỉ số kỳ 2094 → BQL duyệt → BQL tạo rule → đánh giá (FN-06 compare) → cảnh báo tự động →
 * BQL xem / review / ưu tiên / yêu cầu kiểm tra → KT xem case, cập nhật kết quả → BQL xác nhận → đóng → mở lại.
 * KT tạo cảnh báo thủ công. RBAC / CSRF / input sai kiểu / audit / app.log. XOÁ dữ liệu E2E ở cuối (audit giữ lại).
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
$jar = static fn (string $who): string => sys_get_temp_dir() . "/fn07_{$who}_{$runId}.txt";
$logFile = BASE_PATH . '/storage/logs/app.log';
clearstatcache();
$logBefore = filesize($logFile);
$threshold = '300.' . random_int(1, 9); // ngưỡng riêng của lần chạy (không trùng rule thật)

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

    // ================================================================ chuẩn bị: kỳ 2094 + chỉ số đã duyệt
    $form = http($jar('bql'), 'GET', $base . '/periods/create');
    $r = http($jar('bql'), 'POST', $base . '/periods', ['csrf_token' => csrfFrom($form['body']), 'month' => (string) $month, 'year' => '2094']);
    $periodId = preg_match('#/periods/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
    $fx['periods'][] = $periodId;
    $page = http($jar('kt'), 'GET', $base . "/periods/{$periodId}/readings/create?meter_id={$M[1]}");
    http($jar('kt'), 'POST', $base . "/periods/{$periodId}/readings", ['csrf_token' => csrfFrom($page['body']), 'meter_id' => (string) $M[1], 'current_reading' => '1686', 'note' => "E2E {$runId}"]);
    $ap = http($jar('bql'), 'GET', $base . "/periods/{$periodId}/approvals");
    preg_match('/name="token_(\d+)" value="([0-9a-f]{40})"/', $ap['body'], $tm);
    http($jar('bql'), 'POST', $base . "/periods/{$periodId}/approvals", 'csrf_token=' . urlencode(csrfFrom($ap['body'])) . '&reading_ids%5B%5D=' . ($tm[1] ?? 0) . '&token_' . ($tm[1] ?? 0) . '=' . ($tm[2] ?? ''));
    check('SETUP', 'Kỳ 2094: KT nhập BT1-DIEN (CL 343), BQL duyệt → Đã xác nhận', $periodId > 0
        && q($pdo, 'SELECT CONCAT(consumption, status) FROM meter_readings WHERE billing_period_id = ? AND meter_id = ?', [$periodId, $M[1]]) === '343.00da_xac_nhan');

    // ================================================================ BQL tạo rule
    $rf = http($jar('bql'), 'GET', $base . '/alert-rules/create');
    $rcsrf = csrfFrom($rf['body']);
    http($jar('bql'), 'POST', $base . '/alert-rules', ['csrf_token' => $rcsrf, 'name' => "E2E {$runId}", 'measure_type' => 'tieu_thu', 'utility_type' => 'dien', 'compare_base' => 'gia_tri_tuyet_doi',
        'operator' => 'gt', 'threshold_value' => '-1', 'scope_type' => 'toan_toa', 'priority_level' => 'cao']);
    check('RULE', 'Ngưỡng âm → lỗi trên form, không lưu', str_contains(http($jar('bql'), 'GET', $base . '/alert-rules/create')['body'], 'Ngưỡng phải là số &gt; 0') && (int) q($pdo, 'SELECT COUNT(*) FROM alert_rules WHERE name LIKE ?', [e2e_rule_like($runId)]) === 0);
    $r = http($jar('bql'), 'POST', $base . '/alert-rules', ['csrf_token' => $rcsrf, 'name' => "E2E {$runId}", 'measure_type' => 'tieu_thu', 'utility_type' => 'dien', 'compare_base' => 'gia_tri_tuyet_doi',
        'operator' => 'gt', 'threshold_value' => $threshold, 'scope_type' => 'usage_type', 'scope_usage' => 'sinh_hoat', 'priority_level' => 'cao']);
    $ruleId = preg_match('#/alert-rules/(\d+)$#', $r['location'], $m) ? (int) $m[1] : 0;
    check('RULE', "BQL tạo quy tắc tiêu thụ điện SH > {$threshold} kWh", $ruleId > 0 && q($pdo, 'SELECT name FROM alert_rules WHERE id = ?', [$ruleId]) === "E2E {$runId}" && q($pdo, 'SELECT status FROM alert_rules WHERE id = ?', [$ruleId]) === 'bat');
    $dry = http($jar('bql'), 'GET', $base . "/alert-rules/{$ruleId}?period_id={$periodId}");
    check('RULE', 'Chạy thử trên kỳ 2094: 1 trường hợp, không sinh cảnh báo', str_contains($dry['body'], '<strong>1</strong> trường hợp') && (int) q($pdo, 'SELECT COUNT(*) FROM alerts WHERE billing_period_id = ?', [$periodId]) === 0);
    check('RULE', 'Kế toán xem danh sách quy tắc (View), không có nút thêm', ($kr = http($jar('kto'), 'GET', $base . '/alert-rules'))['code'] === 200 && !str_contains($kr['body'], '/alert-rules/create'));

    // ================================================================ đánh giá → cảnh báo tự động
    $al = http($jar('bql'), 'GET', $base . '/alerts');
    $r = http($jar('bql'), 'POST', $base . '/alerts/evaluate', ['csrf_token' => csrfFrom($al['body']), 'period_id' => (string) $periodId]);
    $alertId = (int) q($pdo, 'SELECT id FROM alerts WHERE alert_rule_id = ? AND billing_period_id = ? AND meter_id = ?', [$ruleId, $periodId, $M[1]]);
    check('AUTO', 'BQL "Đánh giá ngưỡng" → 1 cảnh báo tự động (Mới, ưu tiên Cao)', $r['code'] === 302 && $alertId > 0 && q($pdo, 'SELECT CONCAT(status, priority, source) FROM alerts WHERE id = ?', [$alertId]) === 'moicaorule');
    http($jar('bql'), 'POST', $base . '/alerts/evaluate', ['csrf_token' => csrfFrom($al['body']), 'period_id' => (string) $periodId]);
    check('AUTO', 'Đánh giá lại → không sinh trùng', (int) q($pdo, 'SELECT COUNT(*) FROM alerts WHERE alert_rule_id = ? AND billing_period_id = ?', [$ruleId, $periodId]) === 1);
    $list = http($jar('bql'), 'GET', $base . "/alerts?period_id={$periodId}");
    check('LIST', 'Danh sách (lọc kỳ) có cảnh báo, ưu tiên/trạng thái dạng chữ', str_contains($list['body'], '/alerts/' . $alertId . '"') && str_contains($list['body'], '▲▲ Cao') && str_contains($list['body'], '>Mới<'));
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    check('DETAIL', 'Chi tiết "Vì sao có cảnh báo?": actual 343, ngưỡng > ' . $threshold . ', quy tắc, câu giải thích, BR-29, mục khuyến nghị FN-08',
        $d['code'] === 200 && str_contains($d['body'], 'Vì sao có cảnh báo?') && str_contains($d['body'], '343 kWh') && str_contains($d['body'], '&gt; ' . $threshold)
        && str_contains($d['body'], 'không phải kết luận') && str_contains($d['body'], 'FN-08'));
    check('RBAC', 'Kỹ thuật chưa được giao → GET chi tiết cảnh báo (URL trực tiếp) 403', http($jar('kt'), 'GET', $base . '/alerts/' . $alertId)['code'] === 403);

    // ================================================================ BQL review / ưu tiên / yêu cầu kiểm tra
    $csrf = csrfFrom($d['body']);
    $r = http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/review", ['csrf_token' => $csrf, 'version_token' => tokenFrom($d['body']), 'priority' => 'trung_binh', 'note' => 'Xem lại']);
    check('REVIEW', 'BQL review + ưu tiên Cao → Trung bình', q($pdo, 'SELECT CONCAT(status, priority) FROM alerts WHERE id = ?', [$alertId]) === 'da_reviewtrung_binh');
    $r = http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/review", ['csrf_token' => $csrf, 'version_token' => tokenFrom($d['body']), 'priority' => 'cao']);
    check('REVIEW', 'Review lần 2 bằng token cũ → từ chối (không ghi đè)', q($pdo, 'SELECT priority FROM alerts WHERE id = ?', [$alertId]) === 'trung_binh');
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/priority", ['csrf_token' => $csrf, 'version_token' => tokenFrom($d['body']), 'priority' => 'cao', 'note' => 'Tăng ưu tiên']);
    check('REVIEW', 'Đổi ưu tiên → Cao', q($pdo, 'SELECT priority FROM alerts WHERE id = ?', [$alertId]) === 'cao');
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/request-verification", ['csrf_token' => $csrf, 'version_token' => tokenFrom($d['body']), 'content' => '', 'assigned_to' => '2']);
    check('VERIFY', 'Yêu cầu kiểm tra thiếu nội dung → lỗi trong modal', str_contains(http($jar('bql'), 'GET', $base . '/alerts/' . $alertId)['body'], 'Vui lòng nhập nội dung cần kiểm tra.'));
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/request-verification", ['csrf_token' => $csrf, 'version_token' => tokenFrom($d['body']), 'content' => 'Đọc lại công tơ BT1-DIEN', 'assigned_to' => '2']);
    check('VERIFY', 'BQL yêu cầu kiểm tra, giao kythuat01 → Cần kiểm tra lại, case Đã giao', q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'can_kiem_tra_lai'
        && q($pdo, 'SELECT status FROM inspection_cases WHERE alert_id = ?', [$alertId]) === 'da_giao');

    // ================================================================ Kỹ thuật
    $kl = http($jar('kt'), 'GET', $base . '/alerts');
    check('TECH', 'Kỹ thuật thấy cảnh báo được giao trong danh sách', str_contains($kl['body'], '/alerts/' . $alertId . '"'));
    $kd = http($jar('kt'), 'GET', $base . '/alerts/' . $alertId);
    check('TECH', 'Kỹ thuật xem case + form cập nhật kết quả; không có nút review/đóng', $kd['code'] === 200 && str_contains($kd['body'], 'Cập nhật kết quả kiểm tra') && !str_contains($kd['body'], 'data-open-modal="closeModal"'));
    $r = http($jar('kt'), 'POST', $base . "/alerts/{$alertId}/verification-result", 'csrf_token=' . urlencode(csrfFrom($kd['body'])) . '&result_conclusion[]=x&rechecked_value[]=1');
    check('ROBUST', 'Kết quả với mảng (result_conclusion[]) → lỗi validation, không 500', $r['code'] === 302 && q($pdo, 'SELECT status FROM inspection_cases WHERE alert_id = ?', [$alertId]) === 'da_giao');
    http($jar('kt'), 'POST', $base . "/alerts/{$alertId}/verification-result", ['csrf_token' => csrfFrom($kd['body']), 'result_conclusion' => 'canh_bao_dung', 'rechecked_value' => '1686', 'note' => 'Đọc lại đúng']);
    check('TECH', 'Kỹ thuật cập nhật kết quả → case Đã có kết quả; cảnh báo vẫn Cần kiểm tra lại', q($pdo, 'SELECT status FROM inspection_cases WHERE alert_id = ?', [$alertId]) === 'da_co_ket_qua'
        && q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'can_kiem_tra_lai');
    check('RBAC', 'Kỹ thuật POST đóng cảnh báo → 403', http($jar('kt'), 'POST', $base . "/alerts/{$alertId}/close", ['csrf_token' => csrfFrom($kd['body']), 'close_reason' => 'x'])['code'] === 403);

    // ================================================================ BQL xác nhận / đóng / mở lại
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    check('CONFIRM', 'BQL thấy kết quả và form xác nhận / điều chỉnh', str_contains($d['body'], 'Cảnh báo đúng') && str_contains($d['body'], 'Xác nhận / điều chỉnh dữ liệu'));
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/confirm", ['csrf_token' => $csrf, 'version_token' => tokenFrom($d['body']), 'decision' => 'confirm', 'note' => 'Đúng thực tế']);
    check('CONFIRM', 'Xác nhận dữ liệu đúng → Đã xác nhận, case Đã đóng', q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'da_xac_nhan' && q($pdo, 'SELECT status FROM inspection_cases WHERE alert_id = ?', [$alertId]) === 'da_dong');
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/close", ['csrf_token' => $csrf, 'version_token' => tokenFrom($d['body']), 'close_reason' => '']);
    check('CLOSE', 'Đóng thiếu lý do → lỗi, chưa đóng', str_contains(http($jar('bql'), 'GET', $base . '/alerts/' . $alertId)['body'], 'Vui lòng nhập lý do.') && q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'da_xac_nhan');
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/close", ['csrf_token' => $csrf, 'version_token' => tokenFrom($d['body']), 'close_reason' => 'Đã xác nhận, không cần xử lý thêm']);
    check('CLOSE', 'Đóng → Đã đóng', q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'da_dong');
    check('CLOSE', 'Danh sách mặc định (chưa đóng) không còn cảnh báo này', !str_contains(http($jar('bql'), 'GET', $base . "/alerts?period_id={$periodId}")['body'], '/alerts/' . $alertId . '"'));
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/reopen", ['csrf_token' => $csrf, 'version_token' => tokenFrom($d['body']), 'reopen_reason' => 'Có <b>thông tin</b> mới']);
    $d = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    check('REOPEN', 'Mở lại → Đã review; lý do hiển thị đã escape', q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'da_review'
        && str_contains($d['body'], 'Có &lt;b&gt;thông tin&lt;/b&gt; mới') && !str_contains($d['body'], '<b>thông tin</b>'));

    // ================================================================ Kỹ thuật tạo thủ công
    $mf = http($jar('kt'), 'GET', $base . '/alerts/create');
    http($jar('kt'), 'POST', $base . '/alerts', ['csrf_token' => csrfFrom($mf['body']), 'apartment_id' => (string) $A['BT1'], 'meter_id' => (string) $M[2], 'billing_period_id' => (string) $periodId, 'utility_type' => 'nuoc', 'description' => '', 'priority' => 'cao']);
    check('MANUAL', 'Thiếu mô tả → "Vui lòng mô tả hiện tượng bất thường."', str_contains(http($jar('kt'), 'GET', $base . '/alerts/create')['body'], 'Vui lòng mô tả hiện tượng bất thường.'));
    $r = http($jar('kt'), 'POST', $base . '/alerts', ['csrf_token' => csrfFrom($mf['body']), 'apartment_id' => (string) $A['BT1'], 'meter_id' => (string) $M[3], 'billing_period_id' => (string) $periodId, 'utility_type' => 'dien', 'description' => 'x', 'priority' => 'cao']);
    check('MANUAL', 'Công tơ không thuộc căn → lỗi', str_contains(http($jar('kt'), 'GET', $base . '/alerts/create')['body'], 'Công tơ không thuộc căn hộ đã chọn.'));
    $r = http($jar('kt'), 'POST', $base . '/alerts', ['csrf_token' => csrfFrom($mf['body']), 'apartment_id' => (string) $A['BT1'], 'meter_id' => (string) $M[2], 'billing_period_id' => (string) $periodId, 'utility_type' => 'nuoc', 'description' => 'Công tơ quay khi khóa van', 'priority' => 'cao']);
    $manualId = preg_match('#/alerts/(\d+)$#', $r['location'], $m) ? (int) $m[1] : 0;
    check('MANUAL', 'KT tạo cảnh báo thủ công (nguồn Thủ công, Mới), xem được', $manualId > 0 && q($pdo, 'SELECT CONCAT(source, status) FROM alerts WHERE id = ?', [$manualId]) === 'thu_congmoi'
        && http($jar('kt'), 'GET', $base . '/alerts/' . $manualId)['code'] === 200);
    $r = http($jar('kt'), 'POST', $base . '/alerts', 'csrf_token=' . urlencode(csrfFrom($mf['body'])) . '&apartment_id[]=1&description[]=x&priority[]=cao');
    check('ROBUST', 'Tạo thủ công với mảng → lỗi validation, không 500', $r['code'] === 302);

    // ================================================================ RBAC / CSRF
    check('RBAC', 'Kế toán xem danh sách cảnh báo (View)', http($jar('kto'), 'GET', $base . '/alerts')['code'] === 200);
    check('RBAC', 'Kế toán POST review → 403 (J-24 chưa chốt)', http($jar('kto'), 'POST', $base . "/alerts/{$alertId}/review", ['csrf_token' => 'x'])['code'] === 403);
    check('RBAC', 'Kỹ thuật POST đánh giá ngưỡng → 403', http($jar('kt'), 'POST', $base . '/alerts/evaluate', ['csrf_token' => 'x', 'period_id' => (string) $periodId])['code'] === 403);
    check('RBAC', 'Kế toán GET /alert-rules/create → 403', http($jar('kto'), 'GET', $base . '/alert-rules/create')['code'] === 403);
    check('RBAC', 'BQL GET /alerts/create (thủ công là quyền KT) → 403', http($jar('bql'), 'GET', $base . '/alerts/create')['code'] === 403);
    check('RBAC', 'Chỉ 3 vai trò: admin01 không đăng nhập được (J-01 đã chốt)', !login($base, $jar('adm'), 'admin01'));
    $r = http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/close", ['csrf_token' => 'invalid', 'close_reason' => 'x', 'version_token' => 'x']);
    check('CSRF', 'POST đóng với CSRF sai → 403, không đổi trạng thái', $r['code'] === 403 && q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'da_review');
    check('ROBUST', 'GET /alerts với filter rác (period_id[]=1, status=xyz) → 200', http($jar('bql'), 'GET', $base . '/alerts?period_id[]=1&status=xyz&priority[]=a')['code'] === 200);
    check('ROBUST', 'GET /alerts/abc → 404', http($jar('bql'), 'GET', $base . '/alerts/abc')['code'] === 404);

    // ================================================================ Audit + log
    $seen = $pdo->query("SELECT action, COUNT(*) FROM audit_logs WHERE entity_type = 'alert' AND entity_id IN ('{$alertId}', '{$manualId}') GROUP BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
    check('AUDIT', 'Audit CREATE_ALERT_AUTO / MANUAL, REVIEW, CHANGE_PRIORITY, REQUEST / UPDATE_VERIFICATION, CONFIRM_ALERT_DATA, CLOSE, REOPEN',
        ($seen['CREATE_ALERT_AUTO'] ?? 0) == 1 && ($seen['CREATE_ALERT_MANUAL'] ?? 0) == 1 && ($seen['REVIEW_ALERT'] ?? 0) == 1 && ($seen['CHANGE_ALERT_PRIORITY'] ?? 0) >= 2
        && ($seen['REQUEST_ALERT_VERIFICATION'] ?? 0) == 1 && ($seen['UPDATE_ALERT_VERIFICATION'] ?? 0) == 1 && ($seen['CONFIRM_ALERT_DATA'] ?? 0) == 1
        && ($seen['CLOSE_ALERT'] ?? 0) == 1 && ($seen['REOPEN_ALERT'] ?? 0) == 1, json_encode($seen));
    clearstatcache();
    $newLog = (string) file_get_contents($logFile, false, null, $logBefore);
    check('LOG', 'app.log: không có lỗi PHP / Uncaught (CSRF sai ghi [SECURITY])', !preg_match('/PHP Error|Fatal Error|Uncaught/i', $newLog) && str_contains($newLog, '[SECURITY] CSRF token rejected'));
} finally {
    e2e_cleanup($pdo, $fx); // kỳ test + fixture (+ cảnh báo, case gắn vào chúng)
    e2e_rules_cleanup($pdo, $runId); // quy tắc "E2E <runId>" + cảnh báo của chúng - không đụng quy tắc / cảnh báo người dùng
    // chỉ đếm dữ liệu thuộc phạm vi test (id fixture / id kỳ test / mã lượt chạy) - không đếm cảnh báo của người dùng
    $left = e2e_leftovers($pdo, $fx) + e2e_rules_leftovers($pdo, $runId);
    check('CLEANUP', 'Đã xóa dữ liệu E2E (kỳ test 2094, fixture, quy tắc, cảnh báo, case) khỏi DB thật, audit giữ lại', $left === 0, "còn {$left}");
    foreach (['bql', 'kt', 'kto', 'adm'] as $who) {
        @unlink($jar($who));
    }
}

$failed = 0;
foreach ($results as [$g, $n, $ok, $d]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-12s %s%s\n", $ok ? 'PASS' : 'FAIL', $g, $n, $ok || $d === '' ? '' : "  -> {$d}");
}
printf("\n%d/%d PASS (HTTP test qua %s, run %s)\n", count($results) - $failed, count($results), $base, $runId);
exit($failed === 0 ? 0 : 1);
