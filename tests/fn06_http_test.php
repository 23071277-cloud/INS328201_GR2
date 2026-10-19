<?php

declare(strict_types=1);

/**
 * STEP 10 - Test HTTP FN-06 qua Apache XAMPP THẬT (APP_URL) trên DB của .env (energy_dss).
 *   php tests/fn06_http_test.php
 * BQL: tính thử + lập baseline (nhập tay, hiệu lực 2095 - không ảnh hưởng kỳ thật), sửa, ngừng; đặt / sửa mục tiêu
 * (kỳ 07/2024); xem so sánh (06/2024), theo dõi mục tiêu. RBAC / CSRF / input sai kiểu / audit / app.log.
 * XOÁ dữ liệu E2E ở cuối (audit_logs giữ lại - BR-04).
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/real_db_fixture.php'; // fixture riêng: không phụ thuộc / không sửa dữ liệu thật
restore_error_handler();
restore_exception_handler();

use App\Core\Database;

$base = rtrim((string) config('app.url'), '/');
$pdo = Database::connection();
$runId = date('His') . random_int(10, 99);
$jar = static fn (string $who): string => sys_get_temp_dir() . "/fn06_{$who}_{$runId}.txt";
$logFile = BASE_PATH . '/storage/logs/app.log';
clearstatcache();
$logBefore = filesize($logFile);
$effective = sprintf('2095-%02d-01', random_int(1, 12));
$fx = e2e_fixture_create($pdo, $runId); // baseline / mục tiêu đặt trên căn / tòa fixture, không trên căn thật
$S01 = $fx['apt']['S01'];
// baseline thuộc phạm vi fixture (công tơ / căn / khu / tòa fixture) - không đếm baseline của người dùng
$fxBaselines = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM baselines WHERE (scope_type = 'meter' AND scope_id IN (" . implode(',', $fx['meter']) . "))
    OR (scope_type = 'apartment' AND scope_id IN (" . implode(',', $fx['apt']) . ")) OR (scope_type = 'zone' AND scope_id IN (" . implode(',', $fx['zones']) . ")) OR (scope_type = 'building' AND scope_id = {$fx['building']})")->fetchColumn();

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

try {
    foreach (['bql' => 'bql01', 'kt' => 'kythuat01', 'kto' => 'ketoan01'] as $who => $user) {
        check('LOGIN', "Đăng nhập {$user}", login($base, $jar($who), $user));
    }

    // kỳ test riêng ĐÃ KHOÁ cho tòa fixture (nguồn baseline / so sánh) - không dùng kỳ 06/2024, 07/2024, 07/2026 của người dùng
    $lp = e2e_create_locked_period($pdo, $fx, 2090, $runId);
    check('SETUP', "Kỳ test {$lp['code']} đã khóa cho tòa fixture (điện BT1 343, khu chung cư 150)", q($pdo, 'SELECT status FROM billing_periods WHERE id = ?', [$lp['id']]) === 'da_khoa', json_encode($lp['blocked'], JSON_UNESCAPED_UNICODE));
    $alertScope = 'SELECT COUNT(*) FROM alerts WHERE billing_period_id IN (' . implode(',', $fx['periods']) . ') OR apartment_id IN (' . implode(',', $fx['apt']) . ')';
    $alertsBefore = (int) $pdo->query($alertScope)->fetchColumn();

    // ================================================================ BQL - baseline
    $list = http($jar('bql'), 'GET', $base . '/baselines');
    check('BASELINE', 'GET /baselines 200 + sidebar "Theo dõi & phân tích" (mở sẵn)', $list['code'] === 200 && (bool) preg_match('#<div class="nav-group is-open" data-nav-group>\s*<button[^>]*aria-expanded="true"[^>]*>\s*<span class="nav-group__label">Theo dõi &amp; phân tích</span>#u', $list['body']));
    $form = http($jar('bql'), 'GET', $base . '/baselines/create');
    $csrf = csrfFrom($form['body']);
    // Kỳ nguồn "kỳ trước liền kề" = kỳ test đã khoá (hiệu lực = 2 ngày sau kỳ test) - khu chung cư fixture: CC-2305 150 + CC-2306 0
    $effPreview = date('Y-m-d', strtotime($lp['end'] . ' +2 day'));
    $expectZone = '150';
    $r = http($jar('bql'), 'POST', $base . '/baselines', ['csrf_token' => $csrf, 'intent' => 'preview', 'scope' => 'zone:' . $fx['zones']['CC'], 'utility_type' => 'dien', 'method' => 'ky_truoc', 'effective_date' => $effPreview]);
    check('BASELINE', "Tính thử (kỳ trước, khu chung cư fixture, hiệu lực {$effPreview}) = {$expectZone} (CL đã xác nhận kỳ test {$lp['code']}), KHÔNG lưu",
        $r['code'] === 200 && str_contains($r['body'], "baseline = <strong>{$expectZone}</strong>") && str_contains($r['body'], "{$lp['code']} = {$expectZone}") && $fxBaselines() === 0);
    $nTooMany = (int) q($pdo, "SELECT COUNT(*) FROM billing_periods WHERE status = 'da_khoa' AND end_date < ?", [$effPreview]) + 1;
    $r = http($jar('bql'), 'POST', $base . '/baselines', ['csrf_token' => $csrf, 'intent' => 'preview', 'scope' => 'apartment:' . $S01, 'utility_type' => 'dien', 'method' => 'trung_binh_n_ky', 'n_periods' => (string) $nTooMany, 'effective_date' => $effPreview]);
    check('BASELINE', "Tính thử TB {$nTooMany} kỳ khi chỉ có " . ($nTooMany - 1) . ' kỳ đã khóa → INSUFFICIENT_HISTORY', str_contains($r['body'], 'INSUFFICIENT_HISTORY'));
    $r = http($jar('bql'), 'POST', $base . '/baselines', ['csrf_token' => $csrf, 'intent' => 'save', 'scope' => 'apartment:' . $S01, 'utility_type' => 'dien', 'method' => 'nhap_tay', 'manual_value' => '-5', 'effective_date' => $effective]);
    $back = http($jar('bql'), 'GET', $base . '/baselines/create')['body'];
    check('BASELINE', 'Giá trị âm → lỗi validation trên form, không lưu', $r['code'] === 302 && str_contains($back, 'không được âm') && $fxBaselines() === 0);
    $r = http($jar('bql'), 'POST', $base . '/baselines', ['csrf_token' => $csrf, 'intent' => 'save', 'scope' => 'apartment:' . $S01, 'utility_type' => 'dien', 'method' => 'nhap_tay', 'manual_value' => '123', 'effective_date' => $effective]);
    $bid = preg_match('#/baselines/(\d+)$#', $r['location'], $m) ? (int) $m[1] : 0;
    check('BASELINE', "BQL lập baseline nhập tay S01 (fixture) điện = 123 (hiệu lực {$effective})", $bid > 0 && q($pdo, 'SELECT manual_value FROM baselines WHERE id = ?', [$bid]) === '123.00');
    $r = http($jar('bql'), 'POST', $base . '/baselines', ['csrf_token' => $csrf, 'intent' => 'save', 'scope' => 'apartment:' . $S01, 'utility_type' => 'dien', 'method' => 'nhap_tay', 'manual_value' => '124', 'effective_date' => $effective]);
    check('BASELINE', 'Trùng (phạm vi, tiện ích, ngày hiệu lực) → báo lỗi, không lưu', str_contains(http($jar('bql'), 'GET', $base . '/baselines/create')['body'], 'Đã có baseline hiệu lực') && $fxBaselines() === 1);
    $edit = http($jar('bql'), 'GET', $base . "/baselines/{$bid}/edit");
    $r = http($jar('bql'), 'POST', $base . "/baselines/{$bid}/update", ['csrf_token' => csrfFrom($edit['body']), 'intent' => 'save', 'method' => 'nhap_tay', 'manual_value' => '130', 'effective_date' => $effective, 'version_token' => tokenFrom($edit['body'])]);
    check('BASELINE', 'Sửa phiên bản chưa dùng (123 → 130) với version token', $r['code'] === 302 && q($pdo, 'SELECT manual_value FROM baselines WHERE id = ?', [$bid]) === '130.00');
    $r = http($jar('bql'), 'POST', $base . "/baselines/{$bid}/update", ['csrf_token' => csrfFrom($edit['body']), 'intent' => 'save', 'method' => 'nhap_tay', 'manual_value' => '140', 'effective_date' => $effective, 'version_token' => tokenFrom($edit['body'])]);
    check('BASELINE', 'Sửa lại bằng token cũ → từ chối, không ghi đè', q($pdo, 'SELECT manual_value FROM baselines WHERE id = ?', [$bid]) === '130.00');
    $r = http($jar('bql'), 'POST', $base . '/baselines', ['csrf_token' => $csrf, 'intent' => 'save', 'scope' => 'meter:' . $fx['meter'][1], 'utility_type' => 'dien', 'method' => 'ky_truoc', 'effective_date' => $effPreview]);
    $kbid = preg_match('#/baselines/(\d+)$#', $r['location'], $m) ? (int) $m[1] : 0;
    $show = http($jar('bql'), 'GET', $base . "/baselines/{$kbid}");
    check('BASELINE', "Baseline \"kỳ trước liền kề\" BT1-DIEN fixture: chi tiết ghi kỳ nguồn {$lp['code']} = 343", $kbid > 0 && $show['code'] === 200 && str_contains($show['body'], "{$lp['code']} = 343") && str_contains($show['body'], 'Kỳ trước liền kề'));
    $show = http($jar('bql'), 'GET', $base . "/baselines/{$bid}");
    $r = http($jar('bql'), 'POST', $base . "/baselines/{$bid}/deactivate", ['csrf_token' => csrfFrom($show['body']), 'version_token' => tokenFrom($show['body'])]);
    check('BASELINE', 'Ngừng baseline → Hết hiệu lực', q($pdo, 'SELECT status FROM baselines WHERE id = ?', [$bid]) === 'het_hieu_luc');

    // ================================================================ BQL - mục tiêu (kỳ test RIÊNG, chưa có chỉ số; không đặt vào kỳ thật)
    $pf = http($jar('bql'), 'GET', $base . '/periods/create');
    $r = http($jar('bql'), 'POST', $base . '/periods', ['csrf_token' => csrfFrom($pf['body']), 'month' => (string) random_int(1, 12), 'year' => '2098']);
    $tp = preg_match('#/periods/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
    $fx['periods'][] = $tp;
    $tpCode = (string) q($pdo, 'SELECT period_code FROM billing_periods WHERE id = ?', [$tp]);
    $tf = http($jar('bql'), 'GET', $base . '/targets/create');
    $tcsrf = csrfFrom($tf['body']);
    $r = http($jar('bql'), 'POST', $base . '/targets', ['csrf_token' => $tcsrf, 'scope' => 'building:' . $fx['building'], 'utility_type' => 'nuoc', 'billing_period_id' => (string) $tp, 'target_value' => '100']);
    $tid = (int) q($pdo, "SELECT id FROM consumption_targets WHERE scope_type = 'building' AND scope_id = ? AND utility_type = 'nuoc' AND billing_period_id = ?", [$fx['building'], $tp]);
    check('TARGET', "BQL đặt mục tiêu tòa fixture nước kỳ test {$tpCode} = 100", $r['code'] === 302 && $tid > 0);
    $r = http($jar('bql'), 'POST', $base . '/targets', ['csrf_token' => $tcsrf, 'scope' => 'building:' . $fx['building'], 'utility_type' => 'nuoc', 'billing_period_id' => (string) $tp, 'target_value' => '90']);
    check('TARGET', 'Trùng (phạm vi, tiện ích, kỳ) → "Đã có mục tiêu cho phạm vi và kỳ này."', str_contains(http($jar('bql'), 'GET', $base . '/targets/create')['body'], 'Đã có mục tiêu cho phạm vi và kỳ này.'));
    http($jar('bql'), 'POST', $base . '/targets', ['csrf_token' => $tcsrf, 'scope' => 'apartment:' . $S01, 'utility_type' => 'nuoc', 'billing_period_id' => (string) $tp, 'target_value' => '-1']);
    check('TARGET', 'Mục tiêu âm → lỗi', str_contains(http($jar('bql'), 'GET', $base . '/targets/create')['body'], 'Mục tiêu không được âm.'));
    http($jar('bql'), 'POST', $base . '/targets', ['csrf_token' => $tcsrf, 'scope' => 'meter:' . $fx['meter'][1], 'utility_type' => 'dien', 'billing_period_id' => (string) $tp, 'target_value' => '1']);
    check('TARGET', 'Phạm vi công tơ / scope sai → lỗi', str_contains(http($jar('bql'), 'GET', $base . '/targets/create')['body'], 'căn hộ, khu vực hoặc tòa nhà'));
    $te = http($jar('bql'), 'GET', $base . "/targets/{$tid}/edit");
    $r = http($jar('bql'), 'POST', $base . "/targets/{$tid}/update", ['csrf_token' => csrfFrom($te['body']), 'target_value' => '110', 'version_token' => tokenFrom($te['body'])]);
    check('TARGET', 'Sửa mục tiêu 100 → 110 (version token)', q($pdo, 'SELECT target_value FROM consumption_targets WHERE id = ?', [$tid]) === '110.00');
    http($jar('bql'), 'POST', $base . "/targets/{$tid}/update", ['csrf_token' => csrfFrom($te['body']), 'target_value' => '120', 'version_token' => tokenFrom($te['body'])]);
    check('TARGET', 'Sửa bằng token cũ → từ chối, giữ 110', q($pdo, 'SELECT target_value FROM consumption_targets WHERE id = ?', [$tid]) === '110.00');

    // ================================================================ So sánh + theo dõi
    $cp = http($jar('bql'), 'GET', $base . "/comparisons?period_id={$lp['id']}&scope_type=apartment&utility_type=dien");
    check('COMPARISON', "So sánh kỳ test {$lp['code']} theo căn (điện): BT1 fixture 343," . ' chi phí tham khảo 868.698 đ, không lỗi',
        $cp['code'] === 200 && str_contains($cp['body'], '<td class="num">343</td>') && str_contains($cp['body'], '868.698 đ') && str_contains($cp['body'], 'BR-29'));
    $cp = http($jar('bql'), 'GET', $base . "/comparisons?period_id={$lp['id']}&scope_type=building&utility_type=nuoc");
    check('COMPARISON', 'Theo tòa fixture (nước) = 58 m³; không có kỳ trước → "—"; không hiện NaN/Infinity', $cp['code'] === 200 && str_contains($cp['body'], '<td class="num">58</td>') && !preg_match('/NaN|INF|Infinity/', $cp['body']));
    $cp = http($jar('bql'), 'GET', $base . "/comparisons?period_id={$tp}&scope_type=zone&utility_type=dien");
    check('COMPARISON', "Kỳ test {$tpCode} chưa có chỉ số → \"Chưa đủ dữ liệu\"", $cp['code'] === 200 && str_contains($cp['body'], 'Chưa đủ dữ liệu'));
    $tk = http($jar('kto'), 'GET', $base . "/targets/tracking?period_id={$tp}");
    check('TRACKING', "Kế toán xem theo dõi mục tiêu kỳ test {$tpCode}: mục tiêu E2E 110 m³, \"Chưa đủ dữ liệu\"", $tk['code'] === 200 && str_contains($tk['body'], '110 m³') && str_contains($tk['body'], 'Chưa đủ dữ liệu'));
    check('TRACKING', 'Kỹ thuật xem danh sách mục tiêu (View theo 2.3), không có nút Sửa/Đặt', ($kp = http($jar('kt'), 'GET', $base . '/targets'))['code'] === 200 && !str_contains($kp['body'], '/targets/create') && !str_contains($kp['body'], '/edit'));

    // ================================================================ RBAC / CSRF / input sai kiểu
    check('RBAC', 'Kỹ thuật GET /baselines → 403', http($jar('kt'), 'GET', $base . '/baselines')['code'] === 403);
    check('RBAC', 'Kế toán GET /comparisons → 403 (NEED BA J-02)', http($jar('kto'), 'GET', $base . '/comparisons')['code'] === 403);
    check('RBAC', 'Kế toán POST /targets → 403', http($jar('kto'), 'POST', $base . '/targets', ['csrf_token' => 'x'])['code'] === 403);
    check('RBAC', 'Kỹ thuật POST /baselines → 403', http($jar('kt'), 'POST', $base . '/baselines', ['csrf_token' => 'x'])['code'] === 403);
    check('RBAC', 'Kỹ thuật GET /targets/{id}/edit (URL trực tiếp) → 403', http($jar('kt'), 'GET', $base . "/targets/{$tid}/edit")['code'] === 403);
    check('RBAC', 'Chỉ 3 vai trò: admin01 không đăng nhập được (J-01 đã chốt)', !login($base, $jar('adm'), 'admin01'));
    $r = http($jar('bql'), 'POST', $base . '/targets', ['csrf_token' => 'invalid', 'scope' => 'apartment:' . $S01, 'utility_type' => 'dien', 'billing_period_id' => (string) $tp, 'target_value' => '5']);
    check('CSRF', 'POST /targets CSRF sai → 403, không lưu', $r['code'] === 403 && (int) q($pdo, "SELECT COUNT(*) FROM consumption_targets WHERE scope_type = 'apartment' AND scope_id = ? AND billing_period_id = ? AND utility_type = 'dien'", [$S01, $tp]) === 0);
    $r = http($jar('bql'), 'POST', $base . '/baselines', 'csrf_token=' . urlencode($csrf) . '&intent[]=save&scope[]=apartment:' . $S01 . '&utility_type[]=dien&method=nhap_tay&manual_value[]=1&effective_date=' . $effective);
    check('ROBUST', 'POST /baselines với mảng (scope[], manual_value[]) → không 500', in_array($r['code'], [200, 302], true));
    $r = http($jar('bql'), 'POST', $base . "/targets/{$tid}/update", 'csrf_token=' . urlencode($csrf) . '&target_value[]=1&version_token[]=x');
    check('ROBUST', 'POST /targets/{id}/update với mảng → lỗi validation, không 500', $r['code'] === 302 && q($pdo, 'SELECT target_value FROM consumption_targets WHERE id = ?', [$tid]) === '110.00');
    check('ROBUST', 'GET /comparisons với filter rác (period_id[]=1, scope_type=xyz) → 200', http($jar('bql'), 'GET', $base . '/comparisons?period_id[]=1&scope_type=xyz&utility_type=gas')['code'] === 200);
    check('ROBUST', 'GET /baselines/abc → 404', http($jar('bql'), 'GET', $base . '/baselines/abc')['code'] === 404);

    // ================================================================ Audit + log
    $seen = $pdo->query("SELECT action, COUNT(*) FROM audit_logs WHERE (entity_type = 'baseline' AND entity_id = '{$bid}') OR (entity_type = 'consumption_target' AND entity_id = '{$tid}') GROUP BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
    check('AUDIT', 'Audit CREATE_BASELINE, UPDATE_BASELINE, DEACTIVATE_BASELINE, CREATE_CONSUMPTION_TARGET, UPDATE_CONSUMPTION_TARGET',
        ($seen['CREATE_BASELINE'] ?? 0) == 1 && ($seen['UPDATE_BASELINE'] ?? 0) == 1 && ($seen['DEACTIVATE_BASELINE'] ?? 0) == 1
        && ($seen['CREATE_CONSUMPTION_TARGET'] ?? 0) == 1 && ($seen['UPDATE_CONSUMPTION_TARGET'] ?? 0) == 1, json_encode($seen));
    check('NO_ALERT', 'FN-06 không tạo cảnh báo (không có cảnh báo mới trên kỳ test / căn fixture)', (int) $pdo->query($alertScope)->fetchColumn() === $alertsBefore);
    clearstatcache();
    $newLog = (string) file_get_contents($logFile, false, null, $logBefore);
    check('LOG', 'app.log: không có lỗi PHP / Uncaught (CSRF sai ghi [SECURITY])', !preg_match('/PHP Error|Fatal Error|Uncaught/i', $newLog) && str_contains($newLog, '[SECURITY] CSRF token rejected'));
} finally {
    e2e_cleanup($pdo, $fx); // kỳ test + fixture (+ baseline / mục tiêu gắn vào chúng)
    check('CLEANUP', 'Đã xóa baseline / mục tiêu / kỳ test / fixture E2E khỏi DB thật (audit giữ lại)', e2e_leftovers($pdo, $fx) + $fxBaselines()
        + (int) q($pdo, "SELECT COUNT(*) FROM consumption_targets WHERE (scope_type = 'building' AND scope_id = ?) OR (scope_type = 'apartment' AND scope_id IN (" . implode(',', $fx['apt']) . ")) OR (scope_type = 'meter' AND scope_id IN (" . implode(',', $fx['meter']) . '))', [$fx['building']]) === 0);
    foreach (['bql', 'kt', 'kto', 'adm'] as $who) {
        @unlink($jar($who));
    }
}

$failed = 0;
foreach ($results as [$g, $n, $ok, $d]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-11s %s%s\n", $ok ? 'PASS' : 'FAIL', $g, $n, $ok || $d === '' ? '' : "  -> {$d}");
}
printf("\n%d/%d PASS (HTTP test qua %s, run %s)\n", count($results) - $failed, count($results), $base, $runId);
exit($failed === 0 ? 0 : 1);
