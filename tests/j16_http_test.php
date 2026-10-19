<?php

declare(strict_types=1);

/**
 * [BA Proposal / Assumption J-16] E2E qua HTTP trên DB TẠM + php -S (tests/run_j16_tests.sh). Email: router ép driver "log" - không gửi thật.
 *   php tests/j16_http_test.php <base_url> <db_tạm>
 * Luồng: KT nhập chỉ số (CC-2306 khai báo "không phát sinh", CL điện 25) → BQL duyệt (tự phát hiện) → danh sách / chi tiết 7 khối →
 * Kế toán tính nháp, xác nhận bị chặn (cảnh báo + nút khoá) → BQL review, giao KT → KT gửi kết quả → BQL gửi email, ghi cuộc gọi →
 * BQL kết luận C → Kế toán xác nhận được → BQL đóng. RBAC / CSRF trên mọi endpoint J-16; dashboard có chỉ báo.
 */

[$_, $base, $testDb] = $argv + [null, '', ''];
if ($base === '' || $testDb === '' || $testDb === 'energy_dss') {
    fwrite(STDERR, "Dùng: php tests/j16_http_test.php <base_url> <db_tạm>\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();
$_ENV['DB_DATABASE'] = $testDb;
App\Core\Config::set('database.database', $testDb);
require_once __DIR__ . '/support/legacy_compat.php'; // STEP 4C: chạy nguyên văn trên schema v1 hoặc v2
$pdo = legacy_compat_install();
$base = rtrim($base, '/');
$jar = static fn (string $who): string => sys_get_temp_dir() . "/j16_{$who}_" . getmypid() . '.txt';
const P = 2;

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

    return str_ends_with(http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => $user, 'password' => 'Password@123'])['location'], '/dashboard');
}

function bulkFrom(string $html, string $listName): string
{
    preg_match_all('/name="token_(\d+)" value="([0-9a-f]{40})"/', $html, $m, PREG_SET_ORDER);
    $parts = ['csrf_token=' . urlencode(csrfFrom($html))];
    foreach ($m as $x) {
        $parts[] = urlencode($listName . '[]') . '=' . $x[1];
        $parts[] = 'token_' . $x[1] . '=' . $x[2];
    }

    return implode('&', $parts);
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

function flashOf(string $html): string
{
    preg_match_all('#class="alert alert--(?:success|error|info)"[^>]*>(.*?)</div>#s', $html, $m);

    return trim(html_entity_decode(strip_tags(implode(' | ', $m[1]))));
}

try {
    foreach (['kt' => 'kythuat01', 'bql' => 'bql01', 'kto' => 'ketoan01'] as $who => $user) {
        check('SETUP', "Đăng nhập {$user}", login($base, $jar($who), $user));
    }
    $pdo->exec("UPDATE residents SET email = 'le.thi.d@example.invalid' WHERE apartment_id = 4");

    // ================================================================ 1. Phát hiện
    $G = '1 DETECT';
    $csc = [1 => '1686', 2 => '232', 3 => '800', 4 => '160', 5 => '600', 6 => '74', 7 => '25', 8 => '0'];
    foreach ($csc as $meterId => $value) {
        $page = http($jar('kt'), 'GET', $base . '/periods/' . P . '/readings/create?meter_id=' . $meterId);
        http($jar('kt'), 'POST', $base . '/periods/' . P . '/readings', ['csrf_token' => csrfFrom($page['body']), 'meter_id' => (string) $meterId, 'current_reading' => $value, 'note' => '']);
    }
    check($G, 'KT nhập 8 chỉ số (Nháp) — chưa có cảnh báo J-16', (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ?', [P]) === 8
        && (int) q($pdo, "SELECT COUNT(*) FROM alerts WHERE alert_type = 'no_usage_consumption'") === 0);
    $ap = http($jar('bql'), 'GET', $base . '/periods/' . P . '/approvals');
    http($jar('bql'), 'POST', $base . '/periods/' . P . '/approvals', bulkFrom($ap['body'], 'reading_ids'));
    $alertId = (int) q($pdo, "SELECT id FROM alerts WHERE alert_type = 'no_usage_consumption' AND apartment_id = 4 AND billing_period_id = ?", [P]);
    check($G, 'BQL duyệt → tự phát hiện CC-2306 (khai báo không phát sinh, CL điện 25) — đúng 1 cảnh báo', $alertId > 0 && (int) q($pdo, "SELECT COUNT(*) FROM alerts WHERE alert_type = 'no_usage_consumption'") === 1);

    $list = http($jar('bql'), 'GET', $base . '/alerts?alert_type=no_usage_consumption&period_id=' . P);
    check($G, 'Danh sách lọc Loại cảnh báo = J-16: có #' . $alertId . ', nhãn "' . config('j16.label') . '", tiện ích Điện', $list['code'] === 200 && str_contains($list['body'], '/alerts/' . $alertId . '"')
        && str_contains($list['body'], config('j16.label')) && str_contains($list['body'], 'name="alert_type"'));
    $other = http($jar('bql'), 'GET', $base . '/alerts?alert_type=threshold&period_id=' . P);
    check($G, 'lọc loại FN-07 (threshold) → không có cảnh báo J-16', !str_contains($other['body'], '/alerts/' . $alertId . '"'));
    $show = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    $html = $show['body'];
    check($G, 'Chi tiết: 7 khối (Phát hiện, Dữ liệu công tơ, Trạng thái căn hộ, Liên hệ cư dân, Kết quả Kỹ thuật, Kết luận BQL, Timeline)', $show['code'] === 200
        && str_contains($html, '1. Phát hiện') && str_contains($html, '2. Dữ liệu công tơ') && str_contains($html, '3. Trạng thái căn hộ') && str_contains($html, '4. Liên hệ cư dân')
        && str_contains($html, '5. Kết quả Kỹ thuật') && str_contains($html, '6. Kết luận BQL') && str_contains($html, '7. Timeline xử lý'));
    check($G, 'thông điệp "' . config('j16.review_message') . '" + nhãn BA Proposal; CSĐ/CSC/CL công tơ CC2306-DIEN; khai báo "Không phát sinh"',
        str_contains($html, config('j16.review_message')) && str_contains($html, 'NOT ORIGINAL CONFIRMED REQUIREMENT') && str_contains($html, 'CC2306-DIEN')
        && str_contains($html, '<strong>25</strong> kWh') && str_contains($html, e(label_of('occupancy_status', 'khong_phat_sinh'))));
    check($G, 'không dùng từ kết tội trên trang (rò điện / gian lận / trái phép)', !preg_match('/rò điện|gian lận|trái phép/u', strip_tags($html)));

    // ================================================================ 2. Kế toán: tính nháp được, xác nhận bị chặn
    $G = '2 BILLING GATE';
    $bl = http($jar('kto'), 'GET', $base . '/periods/' . P . '/billing');
    http($jar('kto'), 'POST', $base . '/periods/' . P . '/billing/calculate', ['csrf_token' => csrfFrom($bl['body'])]);
    $chargeId = (int) q($pdo, 'SELECT id FROM apartment_charges WHERE apartment_id = 4 AND billing_period_id = ?', [P]);
    check($G, 'Kế toán tính nháp cả kỳ (4 bản tính) dù có cảnh báo J-16', (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ?', [P]) === 4 && $chargeId > 0);
    $detail = http($jar('kto'), 'GET', $base . '/charges/' . $chargeId)['body'];
    check($G, 'Chi tiết bản tính: thẻ cảnh báo "' . config('j16.label') . '", "Đang BQL xác minh", link Xem cảnh báo, nút Xác nhận bị khoá',
        str_contains($detail, config('j16.label')) && str_contains($detail, 'Đang BQL xác minh') && str_contains($detail, config('j16.billing_warning'))
        && str_contains($detail, '/alerts/' . $alertId . '">Xem cảnh báo') && (bool) preg_match('/<button type="button" class="btn btn--primary" disabled[^>]*>Xác nhận<\/button>/', $detail));
    $rv = http($jar('kto'), 'GET', $base . '/periods/' . P . '/billing/review')['body'];
    check($G, 'màn kiểm tra & xác nhận: dòng CC-2306 gắn "J-16: đang BQL xác minh"', str_contains($rv, 'J-16: đang BQL xác minh'));
    http($jar('kto'), 'POST', $base . '/periods/' . P . '/billing/confirm', bulkFrom($rv, 'charge_ids'));
    $after = http($jar('kto'), 'GET', $base . '/periods/' . P . '/billing/review')['body'];
    check($G, 'Case 11 (HTTP): xác nhận theo lô → 3 căn xác nhận, CC-2306 bị chặn với thông báo gate', q($pdo, 'SELECT status FROM apartment_charges WHERE id = ?', [$chargeId]) === 'da_tinh'
        && (int) q($pdo, "SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ? AND status = 'da_xac_nhan'", [P]) === 3 && str_contains(html_entity_decode($after), 'Chưa thể xác nhận bản tính'),
        flashOf($after));

    // ================================================================ 3. RBAC / CSRF
    $G = '3 RBAC';
    $endpoints = ['contact' => ['contact_type' => 'phone', 'contact_time' => date('Y-m-d\TH:i'), 'result' => 'contacted'], 'email' => ['resident_id' => '4'],
        'conclude' => ['conclusion' => 'real_consumption_confirmed', 'conclusion_note' => 'x'], 'apartment-status' => ['confirm_apartment' => '1'], 'adjust-reading' => ['reading_id' => '1']];
    $denied = [];
    foreach (['kto', 'kt'] as $who) {
        $page = http($jar($who), 'GET', $base . '/dashboard');
        foreach ($endpoints as $ep => $data) {
            $r = http($jar($who), 'POST', $base . "/alerts/{$alertId}/j16/{$ep}", $data + ['csrf_token' => csrfFrom($page['body'])]);
            $denied[] = $r['code'] === 403;
        }
    }
    check($G, 'Kế toán + Kỹ thuật POST 5 endpoint J-16 (liên hệ, email, kết luận, cập nhật căn, điều chỉnh chỉ số) → 403', !in_array(false, $denied, true) && count($denied) === 10);
    $ktoView = http($jar('kto'), 'GET', $base . '/alerts/' . $alertId)['body'];
    check($G, 'Kế toán xem được cảnh báo nhưng không có form kết luận / liên hệ', str_contains($ktoView, '6. Kết luận BQL') && !str_contains($ktoView, '/j16/conclude') && !str_contains($ktoView, '/j16/contact'));
    check($G, 'Kỹ thuật chưa được giao → GET chi tiết 403 (K-18)', http($jar('kt'), 'GET', $base . '/alerts/' . $alertId)['code'] === 403);
    $r = http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/j16/conclude", ['csrf_token' => 'sai', 'conclusion' => 'real_consumption_confirmed', 'conclusion_note' => 'x']);
    check($G, 'BQL POST với CSRF sai → 403, không đổi', $r['code'] === 403 && q($pdo, 'SELECT conclusion FROM alerts WHERE id = ?', [$alertId]) === null);

    // ================================================================ 4. BQL review → Kỹ thuật
    $G = '4 WORKFLOW';
    $html = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId)['body'];
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/review", ['csrf_token' => csrfFrom($html), 'version_token' => tokenFrom($html), 'priority' => 'cao', 'note' => '']);
    $html = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId)['body'];
    check($G, 'BQL review (ưu tiên do BQL chọn: Cao) → Đã review; form yêu cầu kiểm tra điền sẵn lý do J-16', q($pdo, 'SELECT CONCAT(status, priority) FROM alerts WHERE id = ?', [$alertId]) === 'da_reviewcao'
        && str_contains($html, e(config('j16.tech_reason'))));
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/request-verification", ['csrf_token' => csrfFrom($html), 'version_token' => tokenFrom($html), 'content' => config('j16.tech_reason'), 'assigned_to' => '2']);
    $kt = http($jar('kt'), 'GET', $base . '/alerts/' . $alertId);
    $ktList = http($jar('kt'), 'GET', $base . '/alerts')['body'];
    check($G, 'Kỹ thuật được giao: thấy case, lý do, căn hộ / công tơ / kỳ / CSĐ-CSC-CL, form kết quả J-16 (5 lựa chọn, không có kết luận trạng thái căn)',
        $kt['code'] === 200 && str_contains($kt['body'], 'CC2306-DIEN') && str_contains($kt['body'], e(config('j16.tech_reason'))) && str_contains($kt['body'], 'value="cong_to_binh_thuong"')
        && str_contains($kt['body'], 'value="khong_tiep_can"') && !str_contains($kt['body'], 'value="apartment_status_incorrect"') && str_contains($ktList, 'Lý do: ' . e(config('j16.tech_reason'))));
    http($jar('kt'), 'POST', $base . "/alerts/{$alertId}/verification-result", ['csrf_token' => csrfFrom($kt['body']), 'result_conclusion' => 'cong_to_binh_thuong', 'note' => 'Công tơ OK']);
    check($G, 'Case 8 (HTTP): KT gửi kết quả METER_OK → case Đã có kết quả', q($pdo, 'SELECT CONCAT(status, result_conclusion) FROM inspection_cases WHERE alert_id = ?', [$alertId]) === 'da_co_ket_quacong_to_binh_thuong');

    // ================================================================ 5. Liên hệ cư dân (email + gọi điện)
    $G = '5 CONTACT';
    $html = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId)['body'];
    $residentId = (string) q($pdo, 'SELECT id FROM residents WHERE apartment_id = 4');
    check($G, 'BQL thấy người nhận có email (email đầy đủ cho BQL) + form ghi nhận cuộc gọi / phản hồi', str_contains($html, 'le.thi.d@example.invalid') && str_contains($html, 'Ghi nhận "Đã gọi chủ hộ"')
        && str_contains($html, 'Ghi nhận phản hồi cư dân'));
    $r = http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/j16/email", ['csrf_token' => csrfFrom($html), 'resident_id' => $residentId]);
    $n = $pdo->query("SELECT * FROM email_notifications WHERE alert_id = {$alertId}")->fetch();
    check($G, 'Case 7 (HTTP): gửi email xác minh → sent, type consumption_verification, transport log (KHÔNG gửi thật)', $r['code'] === 302 && $n !== false && $n['status'] === 'sent'
        && $n['notification_type'] === 'consumption_verification' && $n['transport'] === 'log');
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/j16/contact", ['csrf_token' => csrfFrom($html), 'contact_type' => 'phone', 'contact_time' => date('Y-m-d\TH:i', time() - 300), 'result' => 'contacted', 'contact_note' => 'Chủ hộ xác nhận đang sửa nhà']);
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/j16/contact", ['csrf_token' => csrfFrom($html), 'contact_type' => 'resident_response', 'contact_time' => date('Y-m-d\TH:i', time() - 60), 'result' => 'dang_sua_chua', 'contact_note' => '']);
    $html = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId)['body'];
    check($G, 'cuộc gọi + phản hồi hiển thị trong khối Liên hệ cư dân và Timeline', (int) q($pdo, 'SELECT COUNT(*) FROM alert_contact_logs WHERE alert_id = ?', [$alertId]) === 2
        && str_contains($html, 'Chủ hộ xác nhận đang sửa nhà') && str_contains($html, 'Phản hồi cư dân: Đang sửa chữa') && str_contains($html, 'Email xác minh tới'));

    // ================================================================ 6. Kết luận → Kế toán xác nhận → đóng
    $G = '6 CONCLUDE';
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/j16/conclude", ['csrf_token' => csrfFrom($html), 'version_token' => tokenFrom($html), 'conclusion' => 'real_consumption_confirmed', 'conclusion_note' => 'Có tiêu thụ thực do sửa nhà']);
    check($G, 'BQL kết luận C → Đã xác nhận, case Đã đóng', q($pdo, 'SELECT CONCAT(status, conclusion) FROM alerts WHERE id = ?', [$alertId]) === 'da_xac_nhanreal_consumption_confirmed'
        && q($pdo, 'SELECT status FROM inspection_cases WHERE alert_id = ?', [$alertId]) === 'da_dong');
    $detail = http($jar('kto'), 'GET', $base . '/charges/' . $chargeId)['body'];
    check($G, 'chi tiết bản tính: hết thẻ J-16, nút Xác nhận hoạt động', !str_contains($detail, 'Đang BQL xác minh') && str_contains($detail, '/billing/confirm'));
    $rv = http($jar('kto'), 'GET', $base . '/periods/' . P . '/billing/review')['body'];
    http($jar('kto'), 'POST', $base . '/periods/' . P . '/billing/confirm', bulkFrom($rv, 'charge_ids'));
    check($G, 'Case 12 (HTTP): Kế toán xác nhận CC-2306 sau kết luận', q($pdo, 'SELECT status FROM apartment_charges WHERE id = ?', [$chargeId]) === 'da_xac_nhan');
    $html = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId)['body'];
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/close", ['csrf_token' => csrfFrom($html), 'version_token' => tokenFrom($html), 'close_reason' => 'Hoàn tất xác minh']);
    check($G, 'BQL đóng → Đã đóng; audit đủ chuỗi J-16', q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'da_dong'
        && (int) q($pdo, "SELECT COUNT(DISTINCT action) FROM audit_logs WHERE action IN ('DETECT_NO_USAGE_CONSUMPTION','REVIEW_NO_USAGE_ALERT','REQUEST_TECH_VERIFICATION','SEND_CONSUMPTION_VERIFICATION_EMAIL',
            'RECORD_RESIDENT_RESPONSE','RECORD_PHONE_CONTACT','CONCLUDE_NO_USAGE_ALERT','CLOSE_NO_USAGE_ALERT')") === 8);

    // ================================================================ 7. Dashboard
    $G = '7 DASHBOARD';
    $pdo->exec("UPDATE apartments SET occupancy_status = 'khong_phat_sinh' WHERE id = 2");
    $html = http($jar('bql'), 'GET', $base . '/alerts?period_id=' . P)['body'];
    http($jar('bql'), 'POST', $base . '/alerts/evaluate', ['csrf_token' => csrfFrom($html), 'period_id' => (string) P]);
    $after = http($jar('bql'), 'GET', $base . '/alerts?period_id=' . P)['body'];
    $dash = http($jar('bql'), 'GET', $base . '/dashboard?period_id=' . P)['body'];
    check($G, 'BQL "Đánh giá ngưỡng" cũng chạy J-16 (S01 vừa khai báo không phát sinh) + thông báo kết quả', (int) q($pdo, "SELECT COUNT(*) FROM alerts WHERE alert_type = 'no_usage_consumption' AND apartment_id = 2") === 1
        && str_contains(html_entity_decode($after), 'J-16 (căn khai báo không phát sinh có CL > 0): 1 cảnh báo mới'), flashOf($after));
    check($G, 'Dashboard có chỉ báo "' . config('j16.dashboard_label') . '" (1 đang mở)', str_contains($dash, e(config('j16.dashboard_label'))) && str_contains($dash, 'alert_type=no_usage_consumption'));
} finally {
    foreach (['kt', 'bql', 'kto'] as $who) {
        @unlink($jar($who));
    }
}

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
echo "\n{$pass}/{$total} PASS (J-16 HTTP E2E qua {$base}, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
