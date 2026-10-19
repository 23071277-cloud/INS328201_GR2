<?php

declare(strict_types=1);

/**
 * UX - thao tác live (không tải lại trang) + badge việc cần xử lý theo quyền + J-16 "Ngày theo dõi lại". DB TẠM + php -S.
 *   php tests/live_ux_test.php <base_url> <db_tạm>      (gọi qua tests/run_live_ux_tests.sh)
 * 1) Dữ liệu (Service, DB tạm): kỳ 07/2024 - công tơ 1,2,3,4,7,8 đã duyệt (CC-2306 điện 25 -> cảnh báo J-16 Mới), công tơ 6 Nháp,
 *    công tơ 5 chưa ghi; bản tính BT1 Đã tính; 1 email lỗi.
 * 2) HTTP: badge đúng theo vai trò, không lộ số của chức năng không có quyền; việc đã hoàn tất không đếm.
 * 3) Chrome headless (trang điều khiển cùng origin /__live_harness.html): review, kết luận (lỗi + thành công), ghi nhận gọi điện,
 *    duyệt chỉ số (modal xác nhận), đánh giá ngưỡng, Kỹ thuật "Lưu & nhập tiếp" - không tải lại trang, toast, badge giảm ngay.
 */

[$_, $base, $testDb] = $argv + [null, '', ''];
if ($base === '' || $testDb === '' || $testDb === 'energy_dss') {
    fwrite(STDERR, "Dùng: php tests/live_ux_test.php <base_url> <db_tạm>\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();
$_ENV['DB_DATABASE'] = $testDb;
App\Core\Config::set('database.database', $testDb);
App\Core\Config::set('mail.driver', 'array');
require_once __DIR__ . '/support/legacy_compat.php'; // STEP 4C: chạy nguyên văn trên schema v1 hoặc v2
$pdo = legacy_compat_install();
$base = rtrim($base, '/');
$_SERVER['REMOTE_ADDR'] = 'live-ux-test';
$jar = static fn (string $who): string => sys_get_temp_dir() . "/lux_{$who}_" . getmypid() . '.txt';

use App\Services\BillingCalculationService;
use App\Services\BillingConfirmationService;
use App\Services\EmailNotificationService;
use App\Services\EmailService;
use App\Services\MeterReadingService;
use App\Services\ReadingApprovalService;

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

function actAs(int $id, string $role): void
{
    $_SESSION = ['user_id' => $id, 'user_role' => $role, 'username' => $role, 'full_name' => $role];
}

function http(string $jar, string $method, string $url, array $post = [], array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);
    preg_match('/^Content-Type:\s*(.+)$/mi', substr($raw, 0, $size), $ct);

    return ['code' => $code, 'location' => trim($m[1] ?? ''), 'type' => trim($ct[1] ?? ''), 'body' => substr($raw, $size)];
}

function csrfFrom(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

function login(string $base, string $jar, string $user): bool
{
    @unlink($jar);

    return http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom(http($jar, 'GET', $base . '/login')['body']), 'username' => $user, 'password' => 'Password@123'])['code'] === 302;
}

/** @return array<string,int|null> khoá => số (0 = ẩn), null = không có thẻ (không có quyền) */
function badges(string $html): array
{
    $out = [];
    foreach (['alerts', 'periods', 'billing', 'emails'] as $k) {
        if (!preg_match('/data-issue-count="' . $k . '"( hidden)?[^>]*><span aria-hidden="true">(\d+)/', $html, $m)) {
            $out[$k] = null;
            continue;
        }
        $out[$k] = $m[1] !== '' ? 0 : (int) $m[2];
    }

    return $out;
}

function findChrome(): ?string
{
    foreach ([(string) getenv('CHROME_BIN'), '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $c) {
        if ($c !== '' && is_file($c) && is_executable($c)) {
            return $c;
        }
    }

    return null;
}

function harness(string $chrome, string $url): ?array
{
    // Không dùng --user-data-dir (làm Chrome headless treo trên macOS); mỗi lần chạy headless đã có hồ sơ tạm riêng (cookie tách biệt).
    // --virtual-time-budget: đợi kịch bản (timer + fetch) chạy xong rồi mới dump DOM.
    $out = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-first-run --window-size=1400,1000 --virtual-time-budget=60000 --dump-dom '
        . escapeshellarg($url) . ' 2>/dev/null');
    preg_match('#<pre id="__result">(.*?)</pre>#s', $out, $m);
    $r = json_decode(html_entity_decode($m[1] ?? ''), true);

    return is_array($r) ? $r : null;
}

$has = static fn (array $toasts, string $type, string $text): bool => array_filter($toasts, static fn (string $t): bool => str_starts_with($t, 'toast--' . $type) && str_contains($t, $text)) !== [];

try {
    // ================================================================ 1. Dữ liệu
    $pdo->exec("UPDATE residents SET email = 'le.thi.d@example.invalid' WHERE apartment_id = 4");
    actAs(2, 'ky_thuat');
    foreach ([1 => '1686', 2 => '232', 3 => '800', 4 => '160', 7 => '25', 8 => '0', 6 => '74'] as $meterId => $value) {
        (new MeterReadingService())->create(2, $meterId, ['current_reading' => $value, 'note' => '']);
    }
    actAs(1, 'bql');
    $items = [];
    foreach ($pdo->query('SELECT * FROM meter_readings WHERE billing_period_id = 2 AND meter_id <> 6')->fetchAll() as $row) {
        $items[(int) $row['id']] = MeterReadingService::versionToken($row);
    }
    (new ReadingApprovalService())->approve(2, $items);
    $alertId = (int) $pdo->query("SELECT id FROM alerts WHERE alert_type = 'no_usage_consumption' AND apartment_id = 4")->fetchColumn();
    actAs(3, 'ke_toan');
    (new BillingCalculationService())->calculatePeriod(2, 1);
    actAs(1, 'bql');
    EmailService::fail('SMTP down (giả lập)');
    (new EmailNotificationService())->sendConsumptionVerification($alertId, (string) $pdo->query('SELECT id FROM residents WHERE apartment_id = 4')->fetchColumn());
    EmailService::fail(null);
    check('SETUP', 'dữ liệu: J-16 Mới #' . $alertId . ', 1 Nháp, 1 công tơ chưa ghi, BT1 Đã tính, 1 email lỗi', $alertId > 0
        && (int) $pdo->query("SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = 2 AND status = 'nhap'")->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM apartment_charges WHERE status = 'da_tinh'")->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM email_notifications WHERE status = 'failed'")->fetchColumn() === 1);

    // ================================================================ 2. Badge theo vai trò (HTTP)
    $G = 'COUNTER';
    foreach (['bql' => 'bql01', 'kt' => 'kythuat01', 'kto' => 'ketoan01'] as $who => $user) {
        login($base, $jar($who), $user);
    }
    $b = badges(http($jar('bql'), 'GET', $base . '/dashboard')['body']);
    check($G, 'BQL: Cảnh báo 1 (J-16 Mới), Kỳ ghi chỉ số 1 (chỉ số Nháp chờ duyệt), Hộp thư 1 (email lỗi); KHÔNG có badge Tính phí (không có quyền xác nhận)',
        $b === ['alerts' => 1, 'periods' => 1, 'billing' => null, 'emails' => 1], json_encode($b));
    $b = badges(http($jar('kt'), 'GET', $base . '/dashboard')['body']);
    check($G, 'Kỹ thuật: Kỳ ghi chỉ số 1 (công tơ chưa ghi); Cảnh báo 0 (không case được giao); KHÔNG có badge Tính phí / Hộp thư',
        $b === ['alerts' => 0, 'periods' => 1, 'billing' => null, 'emails' => null], json_encode($b));
    $b = badges(http($jar('kto'), 'GET', $base . '/dashboard')['body']);
    check($G, 'Kế toán: Tính phí 1 (BT1 cần xác nhận), Hộp thư 1; KHÔNG có badge Cảnh báo / Kỳ ghi chỉ số (chỉ xem)', $b === ['alerts' => null, 'periods' => null, 'billing' => 1, 'emails' => 1], json_encode($b));
    $html = http($jar('bql'), 'GET', $base . '/dashboard')['body'];
    check($G, 'badge có nhãn cho trình đọc màn hình + tooltip; số 0 → ẩn (thẻ hidden)', str_contains($html, 'việc cần xử lý: Cảnh báo cần review / kết luận')
        && str_contains(http($jar('kt'), 'GET', $base . '/dashboard')['body'], 'data-issue-count="alerts" hidden'));

    // việc hoàn tất không đếm
    actAs(3, 'ke_toan');
    $c = $pdo->query('SELECT * FROM apartment_charges WHERE apartment_id = 1 AND billing_period_id = 2')->fetch();
    (new BillingConfirmationService())->confirm(2, [(int) $c['id'] => BillingCalculationService::versionToken($c)]);
    $b = badges(http($jar('kto'), 'GET', $base . '/dashboard')['body']);
    check($G, 'Kế toán xác nhận BT1 → Tính phí 0 (đã hoàn tất không đếm, badge ẩn)', $b['billing'] === 0, json_encode($b));
    actAs(1, 'bql');
    (new EmailNotificationService())->retry((int) $pdo->query("SELECT id FROM email_notifications WHERE status = 'failed'")->fetchColumn());
    $b = badges(http($jar('kto'), 'GET', $base . '/dashboard')['body']);
    check($G, 'email gửi lại thành công → Hộp thư 0', $b['emails'] === 0, json_encode($b));

    // ================================================================ 3. Giao thức live
    $G = 'PROTOCOL';
    $page = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId);
    $live = http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/j16/contact", ['csrf_token' => csrfFrom($page['body']), 'contact_type' => 'phone',
        'contact_time' => date('Y-m-d\TH:i', time() - 60), 'result' => 'no_answer', 'contact_note' => 'proto'], ['X-Live-Request: 1', 'Accept: application/json']);
    $json = json_decode($live['body'], true);
    check($G, 'POST có X-Live-Request → 200 JSON {ok, redirect} (không 302), dữ liệu vẫn lưu', $live['code'] === 200 && str_contains($live['type'], 'application/json')
        && ($json['redirect'] ?? '') === $base . '/alerts/' . $alertId && (int) $pdo->query("SELECT COUNT(*) FROM alert_contact_logs WHERE note = 'proto'")->fetchColumn() === 1, $live['body']);
    $plain = http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/j16/contact", ['csrf_token' => csrfFrom($page['body']), 'contact_type' => 'phone',
        'contact_time' => date('Y-m-d\TH:i', time() - 60), 'result' => 'no_answer', 'contact_note' => 'plain']);
    check($G, 'POST thường (không JS) vẫn 302 như cũ', $plain['code'] === 302 && str_ends_with($plain['location'], '/alerts/' . $alertId));
    $bad = http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/j16/contact", ['csrf_token' => 'sai', 'contact_type' => 'phone'], ['X-Live-Request: 1']);
    check($G, 'live + CSRF sai → 403 (không bỏ qua bảo mật)', $bad['code'] === 403);
    $kto = http($jar('kto'), 'POST', $base . "/alerts/{$alertId}/j16/conclude", ['csrf_token' => csrfFrom(http($jar('kto'), 'GET', $base . '/dashboard')['body'])], ['X-Live-Request: 1']);
    check($G, 'live + không có quyền (Kế toán kết luận) → 403', $kto['code'] === 403);

    // ================================================================ 4. Trình duyệt
    $G = 'BROWSER';
    $chrome = findChrome();
    if ($chrome === null) {
        check($G, 'SKIPPED: không tìm thấy Chrome/Chromium (đặt CHROME_BIN)', true);
    } else {
        $r = harness($chrome, $base . '/__live_harness.html?role=bql&alert=' . $alertId);
        if ($r === null || isset($r['error'])) {
            check($G, 'chạy được kịch bản BQL trong Chrome', false, json_encode($r, JSON_UNESCAPED_UNICODE));
        } else {
            $j = static fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE);
            check($G, 'badge ban đầu (BQL): Cảnh báo 1, Kỳ ghi chỉ số 1', $r['badgeStart']['alerts'] === 1 && $r['badgeStart']['periods'] === 1 && $r['badgeStart']['billing'] === null, $j($r['badgeStart']));
            check($G, 'Review (modal) → KHÔNG tải lại trang, toast Thành công, trạng thái "Đã review", timeline cập nhật, nút Review biến mất',
                $r['review']['noReload'] && $has($r['review']['toasts'], 'success', 'Đã review') && str_contains($r['review']['subtitle'], 'Đã review') && $r['review']['timeline'] && $r['review']['reviewButtonGone'], $j($r['review']));
            check($G, 'J-16 chưa kết luận sau review → badge Cảnh báo vẫn 1 (J-16 cần kết luận)', $r['review']['alertsBadge'] === 1);
            $f = $r['followup'];
            check($G, 'Ngày theo dõi lại: mặc định ẩn; D → hiện + bắt buộc; C → ẩn, không bắt buộc, xoá giá trị; A → ẩn', $f['initial'] === ['hidden' => true, 'required' => false]
                && $f['d'] === ['hidden' => false, 'required' => true] && $f['c'] === ['hidden' => true, 'required' => false, 'value' => ''] && $f['a'] === ['hidden' => true, 'required' => false], $j($f));
            check($G, 'Kết luận thiếu căn cứ → toast Thất bại, lỗi ngay tại ô, không tải lại', $r['invalid']['noReload'] && $has($r['invalid']['toasts'], 'error', 'Thất bại') && $r['invalid']['fieldError'], $j($r['invalid']));
            check($G, 'Kết luận C → toast Thành công, kết luận + trạng thái Đã xác nhận hiển thị ngay, gỡ chặn, badge Cảnh báo 1 → 0, không tải lại',
                $r['conclude']['noReload'] && $has($r['conclude']['toasts'], 'success', 'kết luận') && $r['conclude']['conclusion'] && str_contains($r['conclude']['status'], 'Đã xác nhận')
                && $r['conclude']['alertsBadge'] === 0 && $r['conclude']['gateNote'], $j($r['conclude']));
            check($G, 'Ghi nhận gọi điện → toast, dòng mới trong bảng liên hệ ngay, không tải lại', $r['contact']['noReload'] && $has($r['contact']['toasts'], 'success', 'cuộc gọi') && $r['contact']['logged'], $j($r['contact']));
            check($G, 'Duyệt chỉ số (modal xác nhận chung) → toast, badge Kỳ ghi chỉ số 1 → 0, không tải lại', $r['approve']['noReload'] && $has($r['approve']['toasts'], 'success', 'Đã duyệt 1 chỉ số')
                && $r['approve']['periodsBefore'] === 1 && $r['approve']['periodsAfter'] === 0, $j($r['approve']));
            check($G, 'Đánh giá ngưỡng → toast Thành công + toast Cảnh báo (thông tin J-16), không tải lại', $r['evaluate']['noReload'] && $has($r['evaluate']['toasts'], 'success', 'Đánh giá')
                && $has($r['evaluate']['toasts'], 'warning', 'J-16'), $j($r['evaluate']));
        }
        $r = harness($chrome, $base . '/__live_harness.html?role=kt');
        if ($r === null || isset($r['error'])) {
            check($G, 'chạy được kịch bản Kỹ thuật trong Chrome', false, json_encode($r, JSON_UNESCAPED_UNICODE));
        } else {
            $s = $r['saveNext'];
            check($G, 'Kỹ thuật "Lưu & nhập tiếp" → không tải lại, toast, badge Kỳ ghi chỉ số 1 → 0, dòng CC2305-DIEN → Nháp; không có badge Tính phí / Hộp thư',
                $s['noReload'] && $has($s['toasts'], 'success', 'Đã lưu chỉ số Nháp') && $s['periodsBefore'] === 1 && $s['periodsAfter'] === 0 && $s['row5'] === 'nhap'
                && $s['hasBilling'] === false && $s['hasEmails'] === false, json_encode($s, JSON_UNESCAPED_UNICODE));
        }
    }
    // sau kịch bản trình duyệt cảnh báo đã review + kết luận C: form "Đổi kết luận" có ô ngày theo dõi ẩn sẵn (không JS vẫn đúng) + helper
    $page = http($jar('bql'), 'GET', $base . '/alerts/' . $alertId)['body'];
    if ($chrome !== null) check('SERVER HTML', 'J-16: ô "Ngày theo dõi lại" render ẩn khi không phải D + helper text; kết luận C lưu follow_up_date NULL', (bool) preg_match('/data-followup-field hidden>/', $page)
        && str_contains($page, 'Chọn ngày BQL sẽ kiểm tra lại case khi chưa đủ thông tin để kết luận.') && $pdo->query("SELECT follow_up_date FROM alerts WHERE id = {$alertId}")->fetchColumn() === null);
} finally {
    foreach (['bql', 'kt', 'kto'] as $who) {
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
echo "\n{$pass}/{$total} PASS (live UX + badge qua {$base}, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
