<?php

declare(strict_types=1);

/**
 * STEP 13 - Test HTTP FN-09 qua Apache XAMPP THẬT (APP_URL) trên DB của .env (energy_dss).
 * Cách ly dữ liệu thật: tòa / căn / công tơ / cư dân FIXTURE riêng (tests/support/real_db_fixture.php) + KỲ TEST riêng đã khoá
 * (e2e_pick_period) - không dùng kỳ, căn, cảnh báo có sẵn của người dùng; mọi số liệu kiểm trên tòa fixture.
 *   php tests/fn09_http_test.php
 * BQL: dashboard → chọn kỳ → KPI → biểu đồ → bấm cảnh báo → xem khuyến nghị. Kế toán: báo cáo → phiếu → CSV.
 * Kỹ thuật: chỉ phần được phép. Chỉ 3 vai trò (J-01 đã chốt). RBAC / IDOR / filter sai / mảng / XSS / CSV injection / read-only.
 * Fixture E2E (rule "E2E <runId>" + cảnh báo + khuyến nghị, khu tên XSS, chủ hộ tên công thức) XOÁ ở cuối theo id fixture / kỳ test / mã lượt chạy (audit giữ lại).
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/real_db_fixture.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Database;

$base = rtrim((string) config('app.url'), '/');
$pdo = Database::connection();
$runId = date('His') . random_int(10, 99);
$jar = static fn (string $who): string => sys_get_temp_dir() . "/fn09_{$who}_{$runId}.txt";
$logFile = BASE_PATH . '/storage/logs/app.log';
clearstatcache();
$logBefore = filesize($logFile);

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
    $time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

    return ['code' => $code, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $size), 'headers' => substr($raw, 0, $size), 'time' => $time];
}

function csrfFrom(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);

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

/** Dữ liệu nghiệp vụ thuộc KỲ TEST (bản tính, chỉ số, cảnh báo, khuyến nghị, kỳ) - chỉ phạm vi test, không đọc dữ liệu người dùng. */
function snapshot(PDO $pdo, int $periodId): string
{
    $parts = [];
    foreach (["SELECT id, status, total_due, updated_at, issued_at FROM apartment_charges WHERE billing_period_id = {$periodId}",
        "SELECT id, status, updated_at FROM meter_readings WHERE billing_period_id = {$periodId}",
        "SELECT id, status, priority, updated_at FROM alerts WHERE billing_period_id = {$periodId}",
        "SELECT r.id FROM recommendations r JOIN alerts a ON a.id = r.alert_id WHERE a.billing_period_id = {$periodId}",
        "SELECT id, status, updated_at FROM billing_periods WHERE id = {$periodId}"] as $sql) {
        $parts[] = json_encode($pdo->query($sql . ' ORDER BY 1')->fetchAll());
    }

    return sha1(implode('|', $parts));
}


// ================================================================ fixture riêng: tòa / căn / công tơ / cư dân + kỳ test ĐÃ KHOÁ (Service, như Core E2E)
$periodId = 0;
$fx = e2e_fixture_create($pdo, $runId);
$M = $fx['meter'];
$A = $fx['apt'];
$code = '';
try {
    foreach (['bql' => 'bql01', 'kt' => 'kythuat01', 'kto' => 'ketoan01'] as $who => $user) {
        check('LOGIN', "Đăng nhập {$user}", login($base, $jar($who), $user));
    }

    foreach (['BT1' => ['Phạm Văn A', 'chu_ho'], 'S01' => ['Nguyễn Thị B', 'chu_ho'], 'CC-2305' => ['Trần Văn C', 'khach_thue'], 'CC-2306' => ['Lê Thị D', 'chu_ho']] as $key => [$name, $role]) { // như seed
        $pdo->prepare("INSERT INTO residents (apartment_id, full_name, resident_role, is_active, effective_from) VALUES (?, ?, ?, 1, '2023-01-01')")
            ->execute([$A[$key], $name . ' (E2E ' . $fx['tag'] . ')', $role]);
    }
    $lp = e2e_create_locked_period($pdo, $fx, 2091, $runId); // kỳ test riêng ĐÃ KHOÁ - không dùng kỳ 06/2024, 07/2024, 07/2026 của người dùng
    $periodId = $lp['id'];
    $code = $lp['code'];
    $others = ['created' => $lp['others'], 'blocked' => $lp['blocked']];
    check('SETUP', "Kỳ test {$code} đã khóa: 8 chỉ số fixture + {$others['created']} công tơ khác CL = 0 (chỉ trong kỳ test), mọi bản tính Đã xác nhận",
        q($pdo, 'SELECT status FROM billing_periods WHERE id = ?', [$periodId]) === 'da_khoa' && $others['blocked'] === [], json_encode($others['blocked'], JSON_UNESCAPED_UNICODE));

    // ================================================================ fixture: cảnh báo kỳ test + khuyến nghị; khu tên XSS; chủ hộ tên công thức (đều trong tòa fixture)
    $rf = http($jar('bql'), 'GET', $base . '/alert-rules/create');
    http($jar('bql'), 'POST', $base . '/alert-rules', ['csrf_token' => csrfFrom($rf['body']), 'name' => "E2E {$runId}", 'measure_type' => 'tieu_thu', 'utility_type' => 'dien', 'compare_base' => 'gia_tri_tuyet_doi',
        'operator' => 'gt', 'threshold_value' => '200.' . random_int(1, 9), 'scope_type' => 'usage_type', 'scope_usage' => 'sinh_hoat', 'priority_level' => 'cao']);
    $al = http($jar('bql'), 'GET', $base . '/alerts');
    http($jar('bql'), 'POST', $base . '/alerts/evaluate', ['csrf_token' => csrfFrom($al['body']), 'period_id' => (string) $periodId]);
    $alertId = (int) q($pdo, 'SELECT id FROM alerts WHERE billing_period_id = ? AND meter_id = ? AND alert_rule_id IN (SELECT id FROM alert_rules WHERE name = ?)', [$periodId, $M[1], "E2E {$runId}"]);
    $ad = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/recommendations", ['csrf_token' => csrfFrom($ad['body'])]);
    $pdo->prepare("INSERT INTO zones (building_id, code, name, zone_type, status) VALUES (?, ?, '<script>alert(\"z\")</script>Khu', NULL, 'hoat_dong')")->execute([$fx['building'], 'X' . $fx['tag']]);
    $fx['zones']['XSS'] = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO residents (apartment_id, full_name, resident_role, is_active, effective_from) VALUES (?, '=HYPERLINK(\"http://evil\",\"x\")', 'chu_ho', 1, '2024-01-01')")->execute([$A['CC-2305']]);
    check('SETUP', "Fixture: cảnh báo BT1 fixture kỳ {$code} (343 > 200) + khuyến nghị; khu tên XSS + chủ hộ tên công thức trong tòa fixture", $alertId > 0 && (int) q($pdo, 'SELECT COUNT(*) FROM recommendations WHERE alert_id = ?', [$alertId]) >= 1);

    // ================================================================ BQL dashboard (lọc tòa fixture)
    $before = snapshot($pdo, $periodId);
    $scope = "period_id={$periodId}&building_id={$fx['building']}";
    $d = http($jar('bql'), 'GET', $base . "/dashboard?{$scope}");
    $b = $d['body'];
    check('DASHBOARD', "BQL GET /dashboard (kỳ test, tòa fixture) → 200, tiêu đề + kỳ {$code}", $d['code'] === 200 && str_contains($b, 'Dashboard quyết định') && str_contains($b, "Kỳ <strong>{$code}</strong>"));
    check('DASHBOARD', 'KPI: điện 643 kWh và nước 58 m³ (thẻ riêng), chi phí 2.891.712 đ chính thức', str_contains($b, 'stat-card__value">643<') && str_contains($b, 'stat-card__value">58<')
        && str_contains($b, '2.891.712 đ') && str_contains($b, 'Chính thức (kỳ đã khóa)'));
    check('DASHBOARD', 'KPI cảnh báo đang mở, mục tiêu, khuyến nghị, căn cao hơn baseline có mặt', str_contains($b, 'Cảnh báo đang mở') && str_contains($b, 'Mục tiêu tiêu thụ (FN-06.04)')
        && str_contains($b, 'Khuyến nghị đã sinh (FN-08)') && str_contains($b, 'Căn cao hơn baseline'));
    preg_match_all('#(?:src|href)="(https?://[^"]+\.(?:js|css)(?:\?[^"]*)?)"#', $b, $assets);
    check('DASHBOARD', '2 biểu đồ SVG riêng (điện, nước) vẽ phía server; mọi JS/CSS từ chính app (không CDN, chạy offline)', substr_count($b, '<svg class="chart"') === 2
        && $assets[1] !== [] && array_filter($assets[1], static fn ($u) => !str_starts_with($u, $base)) === [], json_encode($assets[1]));
    check('DASHBOARD', 'Bảng cảnh báo cần xử lý có link tới cảnh báo E2E; khuyến nghị gần đây', str_contains($b, '/alerts/' . $alertId . '"') && str_contains($b, 'Khuyến nghị gần đây'));
    check('DASHBOARD', 'Tên khu chứa <script> trong bộ lọc được escape', str_contains($b, '&lt;script&gt;alert(&quot;z&quot;)&lt;/script&gt;Khu') && !str_contains($b, '<script>alert("z")</script>'));
    check('PERF', 'NFR-02: dashboard trả về < 2 giây (' . round($d['time'], 3) . ' s)', $d['time'] < 2.0);
    $z = http($jar('bql'), 'GET', $base . "/dashboard?{$scope}&zone_id={$fx['zones']['CC']}&utility_type=nuoc");
    check('DASHBOARD', 'Lọc khu chung cư fixture + nước: điện 150, nước 12 (backend lọc); ghi chú giới hạn khu', $z['code'] === 200 && str_contains($z['body'], 'stat-card__value">150<') && str_contains($z['body'], 'stat-card__value">12<') && str_contains($z['body'], 'giới hạn U5'));
    $c = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    check('DASHBOARD', 'Bấm cảnh báo → chi tiết có khuyến nghị (FN-08)', $c['code'] === 200 && str_contains($c['body'], 'Khuyến nghị hỗ trợ quyết định') && str_contains($c['body'], 'Khuyến nghị tham khảo.'));
    $e = http($jar('bql'), 'GET', $base . '/dashboard?period_id=99999999');
    check('ROBUST', 'Kỳ không tồn tại → kỳ gần nhất có dữ liệu, không lỗi', $e['code'] === 200 && str_contains($e['body'], 'kỳ gần nhất có dữ liệu'));
    check('ROBUST', 'Filter mảng / sai (period_id[]=1, building_id=abc, zone_id[]=x) → 200', http($jar('bql'), 'GET', $base . '/dashboard?period_id[]=1&building_id=abc&zone_id[]=x&utility_type[]=y')['code'] === 200);

    // ================================================================ Kế toán: báo cáo, phiếu, CSV (kỳ test, tòa fixture)
    $r = http($jar('kto'), 'GET', $base . "/reports/utility?{$scope}");
    check('REPORT', 'Kế toán xem báo cáo kỳ test (tòa fixture): 4 căn chính thức, tổng phát sinh 4.773.552 đ, điện / nước tổng riêng', $r['code'] === 200 && str_contains($r['body'], '4.773.552 đ')
        && str_contains($r['body'], '643 kWh') && str_contains($r['body'], '58 m³') && str_contains($r['body'], 'Chỉ cộng 4 bản chính thức'));
    check('REPORT', 'Chủ hộ tên dạng công thức hiển thị như văn bản (escape)', str_contains($r['body'], '=HYPERLINK(&quot;http://evil&quot;,&quot;x&quot;)'));
    $csv = http($jar('kto'), 'GET', $base . "/reports/utility/export?{$scope}");
    check('CSV', 'Xuất CSV: text/csv UTF-8, attachment, BOM', $csv['code'] === 200 && stripos($csv['headers'], 'Content-Type: text/csv; charset=utf-8') !== false
        && stripos($csv['headers'], 'attachment; filename="bao-cao-tien-ich-' . str_replace('/', '-', $code)) !== false && str_starts_with($csv['body'], "\xEF\xBB\xBF"), substr($csv['headers'], 0, 400));
    check('CSV', 'CSV injection: ô chủ hộ "=HYPERLINK(…)" được thêm \' và quote đúng', str_contains($csv['body'], "\"'=HYPERLINK(\"\"http://evil\"\",\"\"x\"\")\""));
    check('CSV', 'CSV: dòng tổng 643.00 / 58.00 / 4773552.00; tiếng Việt giữ nguyên', str_contains($csv['body'], 'TỔNG') && str_contains($csv['body'], ',643.00,58.00,') && str_contains($csv['body'], '4773552.00') && str_contains($csv['body'], 'Phạm Văn A'));
    check('CSV', 'Xuất CSV ghi audit EXPORT_UTILITY_REPORT ([ĐX] log xuất dữ liệu cá nhân)', (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE action = 'EXPORT_UTILITY_REPORT' AND user_id = 3 AND created_at >= NOW() - INTERVAL 60 SECOND") >= 1);
    $bt1Charge = (int) q($pdo, 'SELECT id FROM apartment_charges WHERE billing_period_id = ? AND apartment_id = ?', [$periodId, $A['BT1']]);
    $s01Charge = (int) q($pdo, 'SELECT id FROM apartment_charges WHERE billing_period_id = ? AND apartment_id = ?', [$periodId, $A['S01']]);
    $n = http($jar('kto'), 'GET', $base . "/charges/{$bt1Charge}/notice");
    check('BILL', "Phiếu BT1 fixture {$code}: tiêu đề, CSĐ/CSC/CL, bậc 90.300, VAT 64.348, tổng phải thu 1.950.058 đ (= FN-05), nút In / Lưu PDF", $n['code'] === 200
        && str_contains($n['body'], 'THÔNG BÁO KIÊM PHIẾU THU TIỀN') && str_contains($n['body'], 'CSĐ 1.343') && str_contains($n['body'], '90.300 đ') && str_contains($n['body'], '64.348 đ')
        && str_contains($n['body'], '1.950.058 đ') && str_contains($n['body'], 'In / Lưu PDF') && str_contains($n['body'], 'Phạm Văn A'));
    preg_match('/0\d{9}/', strip_tags($n['body']), $phone);
    // bản tính fixture tính qua FN-05 CÓ snapshot cơ sở tính -> hiện cơ sở (80 m², ô tô), không hiện ghi chú G-1 (G-1 chỉ cho bản tính seed không snapshot - test Service FN-09)
    check('BILL', 'Phiếu không hiện số điện thoại (BR-36); có cơ sở tính từ snapshot (80 m², gửi ô tô) nên không có ghi chú G-1; ghi BLOCKED J-19', $phone === [] && str_contains($n['body'], 'J-19')
        && str_contains($n['body'], '80 m²') && str_contains($n['body'], 'Gửi ô tô') && !str_contains($n['body'], '(G-1)'), json_encode(['phone' => $phone]));
    $nb = http($jar('kto'), 'GET', $base . "/periods/{$periodId}/notices");
    $periodCharges = (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ?', [$periodId]);
    check('BILL', 'In phiếu cả kỳ test: 1 phiếu / bản tính của kỳ test, gồm 4 căn fixture', $nb['code'] === 200 && substr_count($nb['body'], 'class="bill panel"') === $periodCharges
        && array_filter($fx['apt_code'], static fn (string $ac): bool => !str_contains($nb['body'], $ac)) === []);
    check('BILL', 'BQL xem phiếu (View) → 200', http($jar('bql'), 'GET', $base . "/charges/{$s01Charge}/notice")['code'] === 200);
    $miss = http($jar('kto'), 'GET', $base . '/charges/99999999/notice');
    check('ROBUST', 'Phiếu của bản tính không tồn tại → chuyển hướng + thông báo, không 500', $miss['code'] === 302);
    check('ROBUST', 'GET /charges/abc/notice → 404', http($jar('kto'), 'GET', $base . '/charges/abc/notice')['code'] === 404);

    // ================================================================ Kỹ thuật / quyền
    $k = http($jar('kt'), 'GET', $base . "/dashboard?{$scope}");
    check('RBAC', 'Kỹ thuật: dashboard 200 có tiêu thụ, KHÔNG có chi phí / baseline / khuyến nghị', $k['code'] === 200 && str_contains($k['body'], 'stat-card__value">643<')
        && !str_contains($k['body'], 'Chi phí tiện ích') && !str_contains($k['body'], 'Căn cao hơn baseline') && !str_contains($k['body'], 'Khuyến nghị'));
    $kr = http($jar('kt'), 'GET', $base . "/reports/utility?{$scope}");
    check('RBAC', 'Kỹ thuật: báo cáo xem giới hạn (không cột tiền, không nút xuất)', $kr['code'] === 200 && !str_contains($kr['body'], 'Tiền điện') && !str_contains($kr['body'], '/reports/utility/export'));
    check('RBAC', 'Kỹ thuật: xuất CSV (URL trực tiếp) → 403', http($jar('kt'), 'GET', $base . "/reports/utility/export?{$scope}")['code'] === 403);
    check('RBAC', 'Kỹ thuật: phiếu thông báo (IDOR /charges/{id}/notice) → 403; in cả kỳ → 403', http($jar('kt'), 'GET', $base . "/charges/{$bt1Charge}/notice")['code'] === 403
        && http($jar('kt'), 'GET', $base . "/periods/{$periodId}/notices")['code'] === 403);
    check('RBAC', 'Chỉ 3 vai trò: admin01 không đăng nhập được (J-01 đã chốt)', !login($base, $jar('adm'), 'admin01'));
    @unlink($jar('anon'));
    check('RBAC', 'Chưa đăng nhập: /dashboard, /reports/utility → chuyển về /login', str_ends_with(http($jar('anon'), 'GET', $base . '/reports/utility')['location'], '/login') && str_ends_with(http($jar('anon'), 'GET', $base . '/dashboard')['location'], '/login'));
    check('READONLY', 'Dashboard / báo cáo / phiếu / CSV không đổi dữ liệu nghiệp vụ của kỳ test (bản tính, chỉ số, cảnh báo, khuyến nghị, kỳ)', snapshot($pdo, $periodId) === $before);
    clearstatcache();
    $newLog = (string) file_get_contents($logFile, false, null, $logBefore);
    check('LOG', 'app.log: không có lỗi PHP / Uncaught', !preg_match('/PHP Error|Fatal Error|Uncaught|Warning/i', $newLog));
} finally {
    $_SESSION = [];
    e2e_cleanup($pdo, $fx);      // kỳ test + tòa / khu (kể cả khu XSS) / căn / công tơ / cư dân fixture (+ cảnh báo, khuyến nghị gắn vào)
    e2e_rules_cleanup($pdo, $runId); // quy tắc "E2E <runId>" - không đụng quy tắc / cảnh báo người dùng
    $left = e2e_leftovers($pdo, $fx) + e2e_rules_leftovers($pdo, $runId)
        + (int) q($pdo, 'SELECT (SELECT COUNT(*) FROM zones WHERE building_id = ?) + (SELECT COUNT(*) FROM residents WHERE apartment_id IN (' . implode(',', array_map('intval', $A)) . '))', [$fx['building']]);
    check('CLEANUP', 'Đã xóa fixture E2E khỏi DB thật theo id fixture / kỳ test / mã lượt chạy (audit giữ lại)', $left === 0, "còn {$left}");
    foreach (['bql', 'kt', 'kto', 'adm', 'anon'] as $who) {
        @unlink($jar($who));
    }
}

$failed = 0;
foreach ($results as [$g, $n, $ok, $d]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-10s %s%s\n", $ok ? 'PASS' : 'FAIL', $g, $n, $ok || $d === '' ? '' : "  -> {$d}");
}
printf("\n%d/%d PASS (HTTP test qua %s, run %s)\n", count($results) - $failed, count($results), $base, $runId);
exit($failed === 0 ? 0 : 1);
