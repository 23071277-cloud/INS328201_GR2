<?php

declare(strict_types=1);

/**
 * FN-10.03 - gửi Gmail phiếu thông báo phí THEO KỲ THỦ CÔNG ("Gửi Gmail cho các hộ" trên kỳ đã khoá; khoá kỳ KHÔNG tự gửi). DB TẠM (tests/run_bill_email_lock_tests.sh) - KHÔNG gửi Gmail thật:
 *   HTTP qua php -S (router ép mail driver "log"); phần Service trong tiến trình dùng driver "array" (giả lập lỗi bằng EmailService::fail);
 *   Chrome headless (/__bill_email_harness.html) kiểm thao tác live không tải lại trang.
 *   php tests/bill_email_lock_test.php <base_url> <db_tạm>
 * Dữ liệu: cư dân căn 1, 2, 3 có email; căn 4 (CC-2306) không có -> "Thiếu email".
 */

[$_, $base, $testDb] = $argv + [null, '', ''];
if ($base === '' || $testDb === '' || $testDb === 'energy_dss') {
    fwrite(STDERR, "Dùng: php tests/bill_email_lock_test.php <base_url> <db_tạm>\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();
$_ENV['DB_DATABASE'] = $testDb;
App\Core\Config::set('database.database', $testDb);
App\Core\Config::set('mail.driver', 'array'); // tiến trình test: transport giả
$pdo = App\Core\Database::connection();
$base = rtrim($base, '/');
$_SERVER['REMOTE_ADDR'] = 'bill-email-test';
$jar = static fn (string $who): string => sys_get_temp_dir() . "/bel_{$who}_" . getmypid() . '.txt';

use App\Services\BillingCalculationService;
use App\Services\BillingConfirmationService;
use App\Services\EmailService;
use App\Services\MeterReadingService;
use App\Services\PeriodBillEmailService;
use App\Services\PeriodClosingService;
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

function http(string $jar, string $method, string $url, array $post = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 120]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
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

function login(string $base, string $jar, string $user): bool
{
    @unlink($jar);

    return http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom(http($jar, 'GET', $base . '/login')['body']), 'username' => $user, 'password' => 'Password@123'])['code'] === 302;
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

function counts(string $html): array
{
    preg_match_all('/data-count="(\w+)">(\d+)</', $html, $m, PREG_SET_ORDER);

    return array_map('intval', array_column($m, 2, 1));
}

function chromeBin(): ?string
{
    foreach ([(string) getenv('CHROME_BIN'), '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $c) {
        if ($c !== '' && is_file($c) && is_executable($c)) {
            return $c;
        }
    }

    return null;
}

/** Trang chi tiết kỳ: nút / nhãn / dòng trạng thái Gmail. */
function periodEmailUi(string $html): array
{
    preg_match('/data-bill-email-status>(.*?)<\/div>/s', $html, $bar);

    return ['send' => str_contains($html, 'data-bill-email-send') && str_contains($html, '>Gửi Gmail cho các hộ</button>'), 'done' => str_contains($html, 'data-bill-email-done'),
        'retry' => str_contains($html, 'data-bill-email-retry'), 'mailbox' => str_contains($html, '>Hộp thư theo kỳ</a>'), 'count' => counts($bar[1] ?? '')];
}

$emails = static fn (PDO $pdo, int $period): int => (int) q($pdo, 'SELECT COUNT(*) FROM email_notifications WHERE billing_period_id = ?', [$period]);

try {
    foreach (['kto' => 'ketoan01', 'bql' => 'bql01', 'kt' => 'kythuat01'] as $who => $user) {
        login($base, $jar($who), $user);
    }
    $pdo->exec("UPDATE residents SET email = CASE apartment_id WHEN 1 THEN 'pham.van.a@example.invalid' WHEN 2 THEN 'nguyen.thi.b@example.invalid' WHEN 3 THEN 'tran.van.c@example.invalid' ELSE NULL END");

    // ================================================================ kỳ đang mở: không có nút; khoá thất bại -> không có email
    $G = 'OPEN PERIOD';
    $pp = http($jar('kto'), 'GET', $base . '/periods/2');
    $ui = periodEmailUi($pp['body']);
    check($G, 'Kỳ đang mở → KHÔNG hiển thị "Gửi Gmail cho các hộ" / "Hộp thư theo kỳ" / dòng trạng thái', $pp['code'] === 200 && !$ui['send'] && !$ui['done'] && !$ui['mailbox'] && $ui['count'] === [], json_encode($ui));
    $r = http($jar('kto'), 'POST', $base . '/periods/2/lock', ['csrf_token' => csrfFrom($pp['body'])]);
    check($G, 'Khóa kỳ THẤT BẠI (chưa đủ chỉ số / bản tính) → kỳ vẫn mở, không tạo email', $r['code'] === 302 && q($pdo, 'SELECT status FROM billing_periods WHERE id = 2') === 'mo'
        && str_contains(http($jar('kto'), 'GET', $base . '/periods/2')['body'], 'Chưa khóa được kỳ') && $emails($pdo, 2) === 0);
    http($jar('kto'), 'POST', $base . '/periods/2/bill-emails/send', ['csrf_token' => csrfFrom($pp['body']), 'back' => 'period']);
    check($G, 'Gửi Gmail theo kỳ chưa khóa (gọi thẳng URL) → từ chối, không tạo email', $emails($pdo, 2) === 0
        && str_contains(http($jar('kto'), 'GET', $base . '/periods/2')['body'], 'Chỉ gửi phiếu theo kỳ sau khi kỳ đã khóa.'));

    // ================================================================ chuẩn bị kỳ 07/2024 đủ điều kiện khoá (Service)
    actAs(2, 'ky_thuat');
    foreach ([1 => '1686', 2 => '232', 3 => '800', 4 => '160', 5 => '600', 6 => '74', 7 => '0', 8 => '0'] as $meterId => $v) {
        (new MeterReadingService())->create(2, $meterId, ['current_reading' => $v, 'note' => '']);
    }
    actAs(1, 'bql');
    $items = [];
    foreach ($pdo->query('SELECT * FROM meter_readings WHERE billing_period_id = 2')->fetchAll() as $row) {
        $items[(int) $row['id']] = MeterReadingService::versionToken($row);
    }
    (new ReadingApprovalService())->approve(2, $items);
    actAs(3, 'ke_toan');
    (new BillingCalculationService())->calculatePeriod(2);
    $tokens = [];
    foreach ($pdo->query('SELECT * FROM apartment_charges WHERE billing_period_id = 2')->fetchAll() as $c) {
        $tokens[(int) $c['id']] = BillingCalculationService::versionToken($c);
    }
    (new BillingConfirmationService())->confirm(2, $tokens);
    check('SETUP', 'Kỳ 07/2024: 8 chỉ số duyệt, 4 bản tính Đã xác nhận, đủ điều kiện khóa; 3 căn có email, CC-2306 không',
        (int) q($pdo, "SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = 2 AND status = 'da_xac_nhan'") === 4 && (new PeriodClosingService())->lockBlockers(2) === []);

    // ================================================================ khoá kỳ: KHÔNG tự gửi
    $G = 'LOCK (NO AUTO SEND)';
    $pp = http($jar('kto'), 'GET', $base . '/periods/2');
    $r = http($jar('kto'), 'POST', $base . '/periods/2/lock', ['csrf_token' => csrfFrom($pp['body'])]);
    $after = http($jar('kto'), 'GET', $base . '/periods/2')['body'];
    check($G, 'Khóa kỳ thành công → Đã khóa, toast "Khóa kỳ thành công." + gợi ý nút; KHÔNG tạo email nào', $r['code'] === 302 && q($pdo, 'SELECT status FROM billing_periods WHERE id = 2') === 'da_khoa'
        && str_contains($after, 'Khóa kỳ thành công.') && str_contains($after, 'Gửi Gmail cho các hộ') && !str_contains($after, 'Email: ') && $emails($pdo, 2) === 0);
    $ui = periodEmailUi($after);
    check($G, 'Kỳ đã khóa (Kế toán) → nút "Gửi Gmail cho các hộ" + "Hộp thư theo kỳ"; trạng thái Đã gửi 0 / Lỗi 0 / Thiếu email 1 / Chưa gửi 3', $ui['send'] && !$ui['done'] && !$ui['retry'] && $ui['mailbox']
        && $ui['count'] === ['sent' => 0, 'failed' => 0, 'missing' => 1, 'pending' => 3], json_encode($ui));
    $ui = periodEmailUi(http($jar('bql'), 'GET', $base . '/periods/2')['body']);
    check($G, 'BQL (có quyền email_notifications.send) → thấy nút', $ui['send'] && $ui['mailbox']);
    $kt = http($jar('kt'), 'GET', $base . '/periods/2');
    $ui = periodEmailUi($kt['body']);
    check($G, 'Kỹ thuật (không có quyền email) → không thấy nút / Hộp thư / trạng thái; POST gửi → 403', $kt['code'] === 200 && !$ui['send'] && !$ui['done'] && !$ui['mailbox'] && $ui['count'] === []
        && http($jar('kt'), 'POST', $base . '/periods/2/bill-emails/send', ['csrf_token' => csrfFrom($kt['body']), 'back' => 'period'])['code'] === 403 && $emails($pdo, 2) === 0);
    check($G, 'Gửi với CSRF sai → 403, không tạo email', http($jar('kto'), 'POST', $base . '/periods/2/bill-emails/send', ['csrf_token' => 'sai', 'back' => 'period'])['code'] === 403 && $emails($pdo, 2) === 0);

    // ================================================================ bấm "Gửi Gmail cho các hộ"
    $G = 'BULK SEND';
    // không phụ thuộc end_date / ngày hiện tại: đẩy ngày kết thúc kỳ ra tương lai (chỉ trên DB tạm) cho phần còn lại của test - gửi vẫn chạy.
    // (Giữ nguyên tới cuối: ngày kỳ in trong phiếu, đổi ngày = đổi nội dung = thư mới hợp lệ theo chống trùng phiếu + người nhận + nội dung.)
    $pdo->exec("UPDATE billing_periods SET end_date = '2099-12-31' WHERE id = 2");
    $r = http($jar('kto'), 'POST', $base . '/periods/2/bill-emails/send', ['csrf_token' => csrfFrom($after), 'back' => 'period']);
    $page = http($jar('kto'), 'GET', $base . '/periods/2')['body'];
    $n = $pdo->query('SELECT * FROM email_notifications WHERE billing_period_id = 2 ORDER BY id')->fetchAll();
    $to = array_column($n, 'recipient_email');
    sort($to);
    check($G, 'Bấm gửi (kể cả end_date ở tương lai) → về trang kỳ, toast "Gửi Gmail cho các hộ: 3 gửi thành công, 1 thiếu email, 0 lỗi."', $r['code'] === 302 && str_ends_with($r['location'], '/periods/2')
        && str_contains($page, 'Gửi Gmail cho các hộ: 3 gửi thành công, 1 thiếu email, 0 lỗi.'));
    check($G, 'Gửi ĐÚNG người nhận: 3 cư dân đang hiệu lực có email của 3 căn (1 thư / người), bill_notice, sent, transport log (không gửi thật)',
        $to === ['nguyen.thi.b@example.invalid', 'pham.van.a@example.invalid', 'tran.van.c@example.invalid'] && array_unique(array_column($n, 'status')) === ['sent']
        && array_unique(array_column($n, 'transport')) === ['log'] && array_unique(array_column($n, 'notification_type')) === ['bill_notice'], json_encode($to));
    check($G, 'Căn thiếu email (CC-2306) không làm lỗi thao tác: đếm "Thiếu email" 1, không tạo bản ghi', !in_array(4, array_map('intval', array_column($n, 'apartment_id')), true));
    $ui = periodEmailUi($page);
    check($G, 'Gửi hết → nhãn "✓ Đã gửi Gmail" thay nút; vẫn có "Hộp thư theo kỳ"; Đã gửi 3 / Lỗi 0 / Thiếu email 1 / Chưa gửi 0', $ui['done'] && !$ui['send'] && $ui['mailbox']
        && $ui['count'] === ['sent' => 3, 'failed' => 0, 'missing' => 1, 'pending' => 0] && str_contains($page, '✓ Đã gửi Gmail'), json_encode($ui));
    $audit = (string) q($pdo, "SELECT new_value FROM audit_logs WHERE action = 'SEND_PERIOD_BILL_EMAILS' AND entity_id = '2' ORDER BY id DESC LIMIT 1");
    check($G, 'Audit SEND_PERIOD_BILL_EMAILS (trigger manual, đếm + căn thiếu email, không ghi địa chỉ email)', str_contains($audit, '"manual"') && str_contains($audit, 'CC-2306') && !str_contains($audit, '@example'));
    $r = http($jar('kto'), 'POST', $base . '/periods/2/bill-emails/send', ['csrf_token' => csrfFrom($after), 'back' => 'period']); // trang đã "Đã gửi Gmail" không còn form: gọi lại như double-submit
    check($G, 'Bấm gửi LẦN 2 → không gửi trùng (vẫn 3 thư), báo "3 đã gửi trước đó"', $emails($pdo, 2) === 3
        && str_contains(http($jar('kto'), 'GET', $base . '/periods/2')['body'], '0 gửi thành công, 1 thiếu email, 0 lỗi, 3 đã gửi trước đó'), 'code ' . $r['code'] . ', emails ' . $emails($pdo, 2));

    // ================================================================ Hộp thư theo kỳ
    $G = 'MAILBOX';
    $mb = http($jar('kto'), 'GET', $base . '/periods/2/bill-emails');
    check($G, 'Hộp thư theo kỳ (Kế toán): Tổng 4, Đã gửi 3, Lỗi 0, Thiếu email 1, Chưa gửi 0; CC-2306 "Thiếu email"; email bị che (BR-36)', $mb['code'] === 200
        && counts($mb['body']) === ['total' => 4, 'sent' => 3, 'failed' => 0, 'missing' => 1, 'pending' => 0] && (bool) preg_match('/data-bill-email-row="4" data-state="missing"/', $mb['body'])
        && !str_contains($mb['body'], 'pham.van.a@example.invalid') && str_contains($mb['body'], 'Hộp thư theo kỳ'), json_encode(counts($mb['body'])));
    $bq = http($jar('bql'), 'GET', $base . '/periods/2/bill-emails');
    check($G, 'BQL xem được (email đầy đủ); Kỹ thuật → 403', $bq['code'] === 200 && str_contains($bq['body'], 'pham.van.a@example.invalid')
        && http($jar('kt'), 'GET', $base . '/periods/2/bill-emails')['code'] === 403);

    // ================================================================ SMTP lỗi -> failed; Gửi lại thư lỗi -> sent
    $G = 'FAILURE + RETRY';
    actAs(3, 'ke_toan');
    $svc = new PeriodBillEmailService();
    $pdo->exec("UPDATE residents SET email = 'nguyen.thi.b2@example.invalid' WHERE apartment_id = 2"); // người nhận mới -> thư mới
    $ui = periodEmailUi(http($jar('kto'), 'GET', $base . '/periods/2')['body']);
    check($G, 'Có người nhận mới chưa gửi → nút "Gửi Gmail cho các hộ" hiện lại (Chưa gửi 1)', $ui['send'] && !$ui['done'] && $ui['count']['pending'] === 1, json_encode($ui));
    EmailService::fail('SMTP connect() failed (giả lập)');
    $s = $svc->sendForPeriod(2, 'manual');
    EmailService::fail(null);
    check($G, 'SMTP lỗi → email_notifications = failed, đếm lỗi; thư đã gửi không gửi lại', $s['failed'] === 1 && $s['already'] === 2 && $s['sent'] === 0
        && q($pdo, "SELECT status FROM email_notifications WHERE recipient_email = 'nguyen.thi.b2@example.invalid'") === 'failed', json_encode($s));
    check($G, 'Lỗi gửi email KHÔNG đổi trạng thái khóa kỳ (vẫn Đã khóa)', q($pdo, 'SELECT status FROM billing_periods WHERE id = 2') === 'da_khoa');
    $pg = http($jar('kto'), 'GET', $base . '/periods/2')['body'];
    $ui = periodEmailUi($pg);
    check($G, 'Trang kỳ: nút "Gửi lại thư lỗi (1)", không "Đã gửi Gmail"; Lỗi 1', $ui['retry'] && str_contains($pg, 'Gửi lại thư lỗi (1)') && !$ui['done'] && $ui['count']['failed'] === 1, json_encode($ui));
    $r = http($jar('kto'), 'POST', $base . '/periods/2/bill-emails/retry-failed', ['csrf_token' => csrfFrom($pg), 'back' => 'period']);
    $pg = http($jar('kto'), 'GET', $base . '/periods/2')['body'];
    $ui = periodEmailUi($pg);
    check($G, 'Bấm "Gửi lại thư lỗi" → sent (attempt 2), về trang kỳ, Lỗi 0 → "✓ Đã gửi Gmail"', $r['code'] === 302 && str_ends_with($r['location'], '/periods/2')
        && q($pdo, "SELECT CONCAT(status, attempt_count) FROM email_notifications WHERE recipient_email = 'nguyen.thi.b2@example.invalid'") === 'sent2'
        && str_contains($pg, 'Gửi lại 1 email lỗi: 1 thành công, 0 vẫn lỗi.') && $ui['done'] && !$ui['retry'] && $ui['count']['failed'] === 0, json_encode($ui));

    // ================================================================ trình duyệt: thao tác live, không tải lại trang
    $G = 'LIVE (BROWSER)';
    $pdo->exec("UPDATE residents SET email = 'tran.van.c2@example.invalid' WHERE apartment_id = 3"); // 1 người nhận mới -> nút hiện lại
    $chrome = chromeBin();
    if ($chrome === null) {
        check($G, 'Không tìm thấy Chrome để chạy test trình duyệt', false);
    } else {
        $out = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-first-run --window-size=1400,1000 --virtual-time-budget=60000 --dump-dom '
            . escapeshellarg($base . '/__bill_email_harness.html?period=2') . ' 2>/dev/null');
        preg_match('#<pre id="__result">(.*?)</pre>#s', $out, $m);
        $h = json_decode(html_entity_decode($m[1] ?? ''), true) ?: [];
        $a = $h['after'] ?? [];
        check($G, 'Trước khi bấm: nút "Gửi Gmail cho các hộ", Chưa gửi 1', ($h['before']['button'] ?? false) && ($h['before']['pending'] ?? null) === 1, json_encode($h, JSON_UNESCAPED_UNICODE));
        check($G, 'Bấm nút → modal xác nhận → gửi bằng fetch: KHÔNG tải lại trang, ở lại /periods/2, toast kết quả', ($a['noReload'] ?? false) && ($a['url'] ?? '') === '/periods/2'
            && array_filter($a['toasts'] ?? [], static fn (string $t): bool => str_contains($t, 'Gửi Gmail cho các hộ: 1 gửi thành công, 1 thiếu email, 0 lỗi')) !== [], json_encode($h, JSON_UNESCAPED_UNICODE));
        check($G, 'Nội dung cập nhật tại chỗ: nút đổi thành "✓ Đã gửi Gmail", Đã gửi 3 / Chưa gửi 0; vẫn có "Hộp thư theo kỳ"', ($a['done'] ?? false) && !($a['button'] ?? true)
            && ($a['mailbox'] ?? false) && ($a['sent'] ?? null) === 3 && ($a['pending'] ?? null) === 0, json_encode($a, JSON_UNESCAPED_UNICODE));
        check($G, 'Thư mới đúng người nhận, transport log', q($pdo, "SELECT CONCAT(status, transport) FROM email_notifications WHERE recipient_email = 'tran.van.c2@example.invalid'") === 'sentlog');
    }

    check('SAFETY', 'Không gọi Gmail thật: mọi thư transport log / array', (int) q($pdo, "SELECT COUNT(*) FROM email_notifications WHERE transport NOT IN ('log','array')") === 0);
} finally {
    foreach (['kto', 'bql', 'kt'] as $who) {
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
echo "\n{$pass}/{$total} PASS (gửi Gmail theo kỳ thủ công qua {$base}, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
