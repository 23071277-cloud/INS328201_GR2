<?php

declare(strict_types=1);

/**
 * STEP 25 - Test HTTP FN-10.03 qua Apache XAMPP THẬT (APP_URL), DB của .env (energy_dss).
 *   php tests/fn10_email_http_test.php
 * Dùng cư dân tạm (email @example.invalid - tên miền dành riêng, không bao giờ nhận thư) gắn căn BT1, bản tính BT1 kỳ 06/2024.
 * Nếu .env đang MAIL_DRIVER=smtp: BỎ QUA các bước gửi (không gửi thư thật trong test tự động).
 * Dọn ở cuối: xoá thông báo + cư dân tạm (audit giữ lại - BR-04).
 */

require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Database;

$base = rtrim((string) config('app.url'), '/');
$pdo = Database::connection();
$runId = date('His') . random_int(10, 99);
$jar = static fn (string $who): string => sys_get_temp_dir() . "/fn10_{$who}_{$runId}.txt";
$logFile = BASE_PATH . '/storage/logs/app.log';
clearstatcache();
$logBefore = filesize($logFile);
$mailLog = BASE_PATH . '/storage/logs/mail.log';
$mailLogBefore = is_file($mailLog) ? filesize($mailLog) : 0;
$auditFrom = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();
$driver = (string) config('mail.driver');
$canSend = $driver !== 'smtp';
const CHARGE = 1;

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

function flashOf(string $html): string
{
    preg_match('/class="alert alert--(success|error|info)">([^<]*)/', $html, $m);

    return ($m[1] ?? '') . ':' . html_entity_decode($m[2] ?? '');
}

function phpError(string $body): bool
{
    return (bool) preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)\b.*\.php/i', $body);
}

$emailA = "e2e.a.{$runId}@example.invalid";
$emailB = "e2e.b.{$runId}@example.invalid";
$residentIds = [];
try {
    foreach (['bql' => 'bql01', 'kt' => 'kythuat01', 'kto' => 'ketoan01'] as $who => $user) {
        check('LOGIN', "Đăng nhập {$user}", login($base, $jar($who), $user));
    }
    $ins = $pdo->prepare("INSERT INTO residents (apartment_id, full_name, resident_role, phone, email, is_active, effective_from) VALUES (1, ?, 'khach_thue', '0987654321', ?, 1, '2024-01-01')");
    $ins->execute(["E2E Người nhận A {$runId}", $emailA]);
    $residentIds['a'] = (int) $pdo->lastInsertId();
    $ins->execute(["E2E Người nhận B {$runId}", $emailB]);
    $residentIds['b'] = (int) $pdo->lastInsertId();
    $otherApt = (int) q($pdo, 'SELECT id FROM residents WHERE apartment_id <> 1 ORDER BY id LIMIT 1');

    // ================================================================ Màn hình phiếu
    $G = 'NOTICE-UI';
    $p = http($jar('kto'), 'GET', $base . '/charges/' . CHARGE . '/notice');
    check($G, 'Kế toán: phiếu có khối "Gửi qua email" + người nhận E2E', $p['code'] === 200 && str_contains($p['body'], 'Gửi qua email (FN-10.03)') && str_contains($p['body'], "E2E Người nhận A {$runId}"));
    check($G, 'Kế toán thấy email ĐÃ CHE (BR-36), không thấy email đầy đủ', str_contains($p['body'], mask_email($emailA)) && !str_contains($p['body'], $emailA));
    $pb = http($jar('bql'), 'GET', $base . '/charges/' . CHARGE . '/notice');
    check($G, 'BQL thấy email đầy đủ', str_contains($pb['body'], $emailA));
    check($G, 'phiếu không hiện SĐT người nhận', !str_contains($p['body'] . $pb['body'], '0987654321'));
    check($G, 'Kỹ thuật: phiếu 403', http($jar('kt'), 'GET', $base . '/charges/' . CHARGE . '/notice')['code'] === 403);

    // ================================================================ Gửi
    $G = 'SEND';
    if (!$canSend) {
        check($G, "BỎ QUA bước gửi: .env đang MAIL_DRIVER=smtp (không gửi thư thật trong test tự động)", true);
    } else {
        $r = http($jar('kto'), 'POST', $base . '/charges/' . CHARGE . '/email', ['csrf_token' => csrfFrom($p['body']), 'resident_id' => (string) $residentIds['a']]);
        $f = flashOf(http($jar('kto'), 'GET', $base . '/charges/' . CHARGE . '/notice')['body']);
        $row = $pdo->prepare('SELECT * FROM email_notifications WHERE recipient_email = ?');
        $row->execute([$emailA]);
        $n = $row->fetch() ?: [];
        check($G, 'Kế toán gửi -> 302 về phiếu, flash thành công', $r['code'] === 302 && str_ends_with($r['location'], '/charges/' . CHARGE . '/notice') && str_starts_with($f, 'success:'), $f);
        check($G, "bản ghi sent, transport {$driver}, tiêu đề đúng", ($n['status'] ?? '') === 'sent' && $n['transport'] === $driver && $n['subject'] === '[Energy DSS] Thông báo phí kỳ 06/2024 - Căn BT1');
        check($G, 'nội dung lưu không có SĐT', !str_contains(($n['body_html'] ?? '') . ($n['body_text'] ?? ''), '0987654321'));
        $p = http($jar('kto'), 'GET', $base . '/charges/' . CHARGE . '/notice');
        http($jar('kto'), 'POST', $base . '/charges/' . CHARGE . '/email', ['csrf_token' => csrfFrom($p['body']), 'resident_id' => (string) $residentIds['a']]);
        $f = flashOf(http($jar('kto'), 'GET', $base . '/charges/' . CHARGE . '/notice')['body']);
        check($G, 'bấm gửi lần 2 -> "Không gửi trùng", vẫn 1 bản ghi', str_contains($f, 'Không gửi trùng') && (int) q($pdo, 'SELECT COUNT(*) FROM email_notifications WHERE recipient_email = ?', [$emailA]) === 1, $f);
        http($jar('bql'), 'POST', $base . '/charges/' . CHARGE . '/email', ['csrf_token' => csrfFrom($pb['body']), 'resident_id' => (string) $residentIds['b']]);
        check($G, 'BQL gửi tới người nhận B -> sent', q($pdo, 'SELECT status FROM email_notifications WHERE recipient_email = ?', [$emailB]) === 'sent');
        $p = http($jar('kto'), 'GET', $base . '/charges/' . CHARGE . '/notice');
        check($G, 'lịch sử gửi hiện trên màn hình phiếu', str_contains($p['body'], 'Đã gửi') && str_contains($p['body'], mask_email($emailB)));
        if ($driver === 'log') {
            clearstatcache();
            $ml = (string) file_get_contents($mailLog, false, null, $mailLogBefore);
            check($G, 'driver log: mail.log có dòng tóm tắt, email đã che, không có nội dung phiếu', str_contains($ml, mask_email($emailA)) && !str_contains($ml, $emailA) && !str_contains($ml, '1.950.058'));
        }
    }

    // ================================================================ Quyền / CSRF / input sai
    $G = 'SECURITY';
    $count = static fn () => (int) q($pdo, 'SELECT COUNT(*) FROM email_notifications WHERE recipient_email IN (?, ?)', [$emailA, $emailB]);
    $before = $count();
    $kd = http($jar('kt'), 'GET', $base . '/dashboard');
    check($G, 'Kỹ thuật: POST /charges/{id}/email -> 403', http($jar('kt'), 'POST', $base . '/charges/' . CHARGE . '/email', ['csrf_token' => csrfFrom($kd['body']), 'resident_id' => (string) $residentIds['a']])['code'] === 403);
    check($G, 'Kỹ thuật: /email-notifications -> 403', http($jar('kt'), 'GET', $base . '/email-notifications')['code'] === 403);
    check($G, 'Kỹ thuật: sidebar không có Hộp thư đi', !str_contains($kd['body'], '/email-notifications"'));
    check($G, 'Chưa đăng nhập: /email-notifications -> /login', str_ends_with(http($jar('anon'), 'GET', $base . '/email-notifications')['location'], '/login'));
    check($G, 'POST thiếu / sai CSRF -> 403', http($jar('kto'), 'POST', $base . '/charges/' . CHARGE . '/email', ['resident_id' => (string) $residentIds['a']])['code'] === 403
        && http($jar('kto'), 'POST', $base . '/charges/' . CHARGE . '/email', ['csrf_token' => 'sai', 'resident_id' => (string) $residentIds['a']])['code'] === 403);
    $p = http($jar('kto'), 'GET', $base . '/charges/' . CHARGE . '/notice');
    $t = csrfFrom($p['body']);
    $bad = [];
    foreach (['resident_id%5B%5D=' . $residentIds['a'], 'resident_id=' . urlencode('1 OR 1=1'), 'resident_id=', 'resident_id=' . $otherApt, 'resident_id=999999'] as $body) {
        $r = http($jar('kto'), 'POST', $base . '/charges/' . CHARGE . '/email', 'csrf_token=' . urlencode($t) . '&' . $body);
        $f = flashOf(http($jar('kto'), 'GET', $base . '/charges/' . CHARGE . '/notice')['body']);
        if ($r['code'] !== 302 || !str_starts_with($f, 'error:') || phpError($r['body'])) {
            $bad[] = $body . ' => ' . $r['code'] . ' ' . $f;
        }
    }
    check($G, 'resident_id[] / SQL / rỗng / cư dân căn khác / không tồn tại -> báo lỗi, không 500', $bad === [], implode(' | ', $bad));
    check($G, 'các lần trên không tạo bản ghi mới', $count() === $before);
    check($G, 'bản tính không tồn tại -> không 500', http($jar('kto'), 'POST', $base . '/charges/999999/email', ['csrf_token' => $t, 'resident_id' => '1'])['code'] === 302);
    check($G, 'routeId không phải số -> 404', http($jar('kto'), 'POST', $base . '/charges/abc/email', ['csrf_token' => $t])['code'] === 404);

    // ================================================================ Hộp thư đi
    $G = 'OUTBOX';
    $o = http($jar('kto'), 'GET', $base . '/email-notifications?apartment=BT1&recipient=' . urlencode("e2e.a.{$runId}"));
    check($G, 'Kế toán: danh sách lọc theo căn + người nhận, email đã che', $o['code'] === 200 && !phpError($o['body']) && ($canSend ? str_contains($o['body'], mask_email($emailA)) && !str_contains($o['body'], $emailA) : true));
    check($G, 'lọc trạng thái failed / ngày -> 200', http($jar('kto'), 'GET', $base . '/email-notifications?status=failed&date_from=' . date('Y-m-d'))['code'] === 200);
    check($G, 'ngày sai / từ > đến -> thông báo lỗi bộ lọc', str_contains(http($jar('kto'), 'GET', $base . '/email-notifications?date_from=2026-02-30')['body'], 'Ngày không hợp lệ')
        && str_contains(http($jar('kto'), 'GET', $base . '/email-notifications?date_from=2026-05-01&date_to=2026-01-01')['body'], 'nhỏ hơn hoặc bằng'));
    check($G, 'bộ lọc dạng mảng -> 200, không warning', !phpError(http($jar('kto'), 'GET', $base . '/email-notifications?status%5B%5D=sent&recipient%5B%5D=x')['body']));
    if ($canSend) {
        $id = (int) q($pdo, 'SELECT id FROM email_notifications WHERE recipient_email = ?', [$emailA]);
        $d = http($jar('kto'), 'GET', $base . "/email-notifications/{$id}");
        check($G, 'chi tiết: xem trước HTML trong iframe sandbox + plain text', $d['code'] === 200 && str_contains($d['body'], '<iframe') && str_contains($d['body'], 'sandbox') && str_contains($d['body'], 'TỔNG PHẢI THU'));
        check($G, 'bản đã gửi: không có nút "Gửi lại"', !str_contains($d['body'], "/email-notifications/{$id}/retry"));
        http($jar('kto'), 'POST', $base . "/email-notifications/{$id}/retry", ['csrf_token' => csrfFrom($d['body'])]);
        $f = flashOf(http($jar('kto'), 'GET', $base . "/email-notifications/{$id}")['body']);
        check($G, 'POST retry bản đã gửi -> từ chối', str_contains($f, 'không gửi lại') && (int) q($pdo, 'SELECT attempt_count FROM email_notifications WHERE id = ?', [$id]) === 1, $f);
        $pdo->prepare("UPDATE email_notifications SET status = 'failed', last_error = 'E2E giả lập lỗi' WHERE id = ?")->execute([$id]);
        $d = http($jar('bql'), 'GET', $base . "/email-notifications/{$id}");
        check($G, 'bản lỗi: có nút "Gửi lại" + hiện lỗi', str_contains($d['body'], "/email-notifications/{$id}/retry") && str_contains($d['body'], 'E2E giả lập lỗi'));
        http($jar('bql'), 'POST', $base . "/email-notifications/{$id}/retry", ['csrf_token' => csrfFrom($d['body'])]);
        check($G, 'BQL gửi lại -> sent, attempt_count 2', q($pdo, 'SELECT CONCAT(status, attempt_count) FROM email_notifications WHERE id = ?', [$id]) === 'sent2');
        check($G, 'Kỹ thuật POST retry -> 403', http($jar('kt'), 'POST', $base . "/email-notifications/{$id}/retry", ['csrf_token' => csrfFrom($kd['body'])])['code'] === 403);
    }
    check($G, 'chi tiết không tồn tại -> 404', http($jar('kto'), 'GET', $base . '/email-notifications/999999')['code'] === 404);

    // ================================================================ Email cư dân (form BQL)
    $G = 'RESIDENT-FORM';
    $ef = http($jar('bql'), 'GET', $base . "/residents/{$residentIds['b']}/edit");
    preg_match('/name="updated_at" value="([^"]+)"/', $ef['body'], $um);
    $post = ['csrf_token' => csrfFrom($ef['body']), 'updated_at' => $um[1] ?? '', 'full_name' => "E2E Người nhận B {$runId}", 'resident_role' => 'khach_thue', 'phone' => '0987654321', 'effective_from' => '2024-01-01', 'effective_to' => ''];
    check($G, 'form sửa cư dân có ô Email', str_contains($ef['body'], 'name="email"'));
    http($jar('bql'), 'POST', $base . "/residents/{$residentIds['b']}/update", $post + ['email' => 'khong-hop-le']);
    check($G, 'email sai -> không lưu', q($pdo, 'SELECT email FROM residents WHERE id = ?', [$residentIds['b']]) === $emailB);
    $ef = http($jar('bql'), 'GET', $base . "/residents/{$residentIds['b']}/edit");
    preg_match('/name="updated_at" value="([^"]+)"/', $ef['body'], $um);
    http($jar('bql'), 'POST', $base . "/residents/{$residentIds['b']}/update", array_merge($post, ['csrf_token' => csrfFrom($ef['body']), 'updated_at' => $um[1] ?? '', 'email' => strtoupper($emailB)]));
    check($G, 'email hợp lệ (chữ hoa) -> lưu chữ thường', q($pdo, 'SELECT email FROM residents WHERE id = ?', [$residentIds['b']]) === $emailB);
    $ap = http($jar('kto'), 'GET', $base . '/apartments/1');
    check($G, 'chi tiết căn: Kế toán thấy email đã che', str_contains($ap['body'], mask_email($emailB)) && !str_contains($ap['body'], $emailB));

    // ================================================================ Log / audit
    $G = 'LOG';
    clearstatcache();
    $newLog = (string) file_get_contents($logFile, false, null, $logBefore);
    check($G, 'app.log: không có lỗi PHP', !preg_match('/PHP Error|Fatal Error|Uncaught/i', $newLog), substr($newLog, 0, 300));
    $audit = (string) q($pdo, "SELECT GROUP_CONCAT(CONCAT(IFNULL(old_value,''), IFNULL(new_value,''))) FROM audit_logs WHERE id > ? AND entity_type = 'email_notification'", [$auditFrom]);
    check($G, 'audit email: không có email đầy đủ, không có mật khẩu', !str_contains($audit, $emailA) && !str_contains($audit, $emailB) && ($canSend ? $audit !== '' : true)
        && ((string) config('mail.password') === '' || !str_contains($audit, (string) config('mail.password'))));
} finally {
    $pdo->prepare('DELETE FROM email_notifications WHERE recipient_email IN (?, ?)')->execute([$emailA, $emailB]);
    foreach ($residentIds as $rid) {
        $pdo->prepare('DELETE FROM residents WHERE id = ?')->execute([$rid]);
    }
    foreach (['bql', 'kt', 'kto', 'anon'] as $who) {
        @unlink($jar($who));
    }
    check('CLEANUP', 'Đã xoá thông báo + cư dân E2E (audit giữ lại)', (int) q($pdo, 'SELECT COUNT(*) FROM email_notifications WHERE recipient_email IN (?, ?)', [$emailA, $emailB]) === 0
        && (int) q($pdo, 'SELECT COUNT(*) FROM residents WHERE email IN (?, ?)', [$emailA, $emailB]) === 0);
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
echo "\n{$pass}/{$total} PASS (HTTP test qua {$base}, MAIL_DRIVER={$driver}, run E2E{$runId})\n";
exit($pass === $total ? 0 : 1);
