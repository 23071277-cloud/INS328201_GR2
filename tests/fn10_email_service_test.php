<?php

declare(strict_types=1);

/**
 * STEP 25 - Test cấp Service FN-10.03 gửi thông báo phí qua email. KHÔNG gửi email thật: driver "array" (bộ nhớ, giả lập lỗi)
 * và driver "smtp" trỏ tới cổng đóng 127.0.0.1:1 (kiểm đường PHPMailer thật mà không ra Internet).
 *   php tests/fn10_email_service_test.php energy_dss_fn10_test
 * Seed: kỳ 06/2024 đã khoá, 4 bản tính (id 1–4, BT1 = 1), cư dân id 1 (BT1, chủ hộ), 2 (S01) …
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

use App\Core\Config;
use App\Core\Database;
use App\Core\ValidationException;
use App\Services\EmailNotificationService;
use App\Services\EmailService;
use App\Services\EmailTransportException;
use App\Services\ResidentService;

$pdo = Database::connection();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
Config::set('mail.driver', 'array');
Config::set('mail.username', 'sender.test@example.invalid');
Config::set('mail.password', 'SECRETAPPPW1234');
$_SERVER['REMOTE_ADDR'] = 'fn10-service-test';
const BQL = 1;
const KTO = 3;
const CHARGE = 1;   // BT1 kỳ 06/2024 (đã khoá - lịch sử)

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

function expectError(string $g, string $n, callable $fn, string $contains = ''): void
{
    try {
        $fn();
        check($g, $n, false, 'không có lỗi');
    } catch (ValidationException $e) {
        $ok = $contains === '' || str_contains($e->getMessage(), $contains);
        check($g, $n, $ok, $ok ? '' : $e->getMessage());
    }
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

function row(PDO $pdo, int $id): array
{
    $s = $pdo->prepare('SELECT * FROM email_notifications WHERE id = ?');
    $s->execute([$id]);

    return $s->fetch() ?: [];
}

function race(string $db, array $jobs): array
{
    $start = microtime(true) + 1.5;
    $procs = $pipes = [];
    foreach ($jobs as $i => $job) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/fn10_worker.php') . ' ' . escapeshellarg($db) . ' ' . sprintf('%F', $start)
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

$svc = new EmailNotificationService();
$residents = new ResidentService();
actAs(BQL, 'bql');

// =========================================================== Email cư dân (FN-02.04 mở rộng)
$G = 'RESIDENT-EMAIL';
$base = ['full_name' => 'Phạm Văn A', 'resident_role' => 'chu_ho', 'phone' => '0901234567', 'effective_from' => '2023-01-01', 'effective_to' => ''];
$tok = fn () => q($pdo, 'SELECT updated_at FROM residents WHERE id = 1');
foreach (['abc', 'a@', '@b.com', 'a b@c.com', "x@y.com\r\nBcc: z@w.com", str_repeat('a', 250) . '@b.com'] as $bad) {
    try {
        $residents->update(1, $base + ['email' => $bad, 'updated_at' => $tok()]);
        check($G, 'email sai "' . addcslashes(mb_substr($bad, 0, 24), "\r\n") . '" -> từ chối', false);
    } catch (ValidationException $e) {
        check($G, 'email sai "' . addcslashes(mb_substr($bad, 0, 24), "\r\n") . '" -> từ chối', isset($e->errors()['email']), json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
    }
}
check($G, 'mọi lần từ chối: email trong DB vẫn NULL', q($pdo, 'SELECT email FROM residents WHERE id = 1') === null);
$residents->update(1, $base + ['email' => '', 'updated_at' => $tok()]);
check($G, 'email rỗng -> lưu NULL (không bắt buộc)', q($pdo, 'SELECT email FROM residents WHERE id = 1') === null);
$residents->update(1, $base + ['email' => 'Pham.Van.A@Example.INVALID', 'updated_at' => $tok()]);
check($G, 'email hợp lệ -> lưu chữ thường', q($pdo, 'SELECT email FROM residents WHERE id = 1') === 'pham.van.a@example.invalid');
check($G, 'mask_email che phần tên', mask_email('pham.van.a@example.invalid') === 'ph••••••••@example.invalid' && mask_email(null) === '—');

// =========================================================== Người nhận
$G = 'RECIPIENT';
actAs(KTO, 'ke_toan');
$recips = $svc->recipientsFor((new \App\Services\BillNoticeService())->forCharge(CHARGE));
check($G, 'người nhận của BT1 = cư dân #1 có email', count($recips) === 1 && (int) $recips[0]['id'] === 1);
foreach (['', 'abc', '1 OR 1=1', '0', '-1'] as $bad) {
    expectError($G, "resident_id '{$bad}' -> từ chối", fn () => $svc->sendBillNotice(CHARGE, $bad), 'chọn người nhận');
}
expectError($G, 'cư dân căn khác (S01, #2) -> từ chối', fn () => $svc->sendBillNotice(CHARGE, '2'), 'không thuộc căn hộ');
$pdo->exec("UPDATE residents SET email = 'nguyen.thi.b@example.invalid' WHERE id = 2");
expectError($G, 'cư dân căn khác dù có email -> từ chối', fn () => $svc->sendBillNotice(CHARGE, '2'), 'không thuộc căn hộ');
$pdo->exec("INSERT INTO residents (apartment_id, full_name, resident_role, email, is_active, effective_from, effective_to) VALUES
    (1, 'Người cũ', 'khach_thue', 'old.tenant@example.invalid', 0, '2022-01-01', '2022-12-31'),
    (1, 'Chưa có email', 'khach_thue', NULL, 1, '2024-01-01', NULL),
    (1, 'Email hỏng', 'khach_thue', 'bad@@example', 1, '2024-01-01', NULL)");
$inactive = (int) q($pdo, "SELECT id FROM residents WHERE full_name = 'Người cũ'");
$noEmail = (int) q($pdo, "SELECT id FROM residents WHERE full_name = 'Chưa có email'");
$badEmail = (int) q($pdo, "SELECT id FROM residents WHERE full_name = 'Email hỏng'");
expectError($G, 'cư dân hết hiệu lực -> từ chối', fn () => $svc->sendBillNotice(CHARGE, (string) $inactive), 'hết hiệu lực');
expectError($G, 'cư dân chưa có email -> từ chối', fn () => $svc->sendBillNotice(CHARGE, (string) $noEmail), 'chưa có email');
expectError($G, 'email trong DB hỏng -> từ chối, không gửi', fn () => $svc->sendBillNotice(CHARGE, (string) $badEmail), 'không hợp lệ');
check($G, 'danh sách người nhận chỉ gồm cư dân hiệu lực có email', count($svc->recipientsFor((new \App\Services\BillNoticeService())->forCharge(CHARGE))) === 2);
check($G, 'các lần từ chối: không tạo bản ghi, không gửi', (int) q($pdo, 'SELECT COUNT(*) FROM email_notifications') === 0 && EmailService::$sent === []);

// =========================================================== Bản tính
$G = 'CHARGE';
$pdo->exec("UPDATE apartment_charges SET status = 'da_tinh' WHERE id = 2");
$pdo->exec("UPDATE residents SET apartment_id = 2 WHERE id = {$noEmail}");
$pdo->exec("UPDATE residents SET email = 'nguyen.thi.b@example.invalid' WHERE id = 2");
expectError($G, 'bản tính chưa xác nhận (Đã tính) -> không gửi', fn () => $svc->sendBillNotice(2, '2'), 'chưa được xác nhận');
$pdo->exec("UPDATE apartment_charges SET status = 'da_phat_hanh' WHERE id = 2");
expectError($G, 'bản tính không tồn tại -> lỗi', fn () => $svc->sendBillNotice(99999, '1'), 'không tồn tại');

// =========================================================== Gửi thành công + nội dung
$G = 'SEND';
$pdo->exec("UPDATE residents SET full_name = 'Phạm Văn A <script>x</script>' WHERE id = 1");
$stateBefore = q($pdo, 'SELECT CONCAT(c.status, IFNULL(c.issued_at, ""), c.updated_at, p.status) FROM apartment_charges c JOIN billing_periods p ON p.id = c.billing_period_id WHERE c.id = 1');
$r = $svc->sendBillNotice(CHARGE, '1');
$n = row($pdo, $r['id']);
$mail = EmailService::$sent[0] ?? [];
check($G, 'gửi thành công: status sent, sent_at, 1 lần, transport array', $r['status'] === 'sent' && $n['status'] === 'sent' && $n['sent_at'] !== null && (int) $n['attempt_count'] === 1 && $n['transport'] === 'array');
check($G, 'tiêu đề tiếng Việt đúng', ($mail['subject'] ?? '') === '[Energy DSS] Thông báo phí kỳ 06/2024 - Căn BT1', $mail['subject'] ?? '');
check($G, 'người nhận = email cư dân (chữ thường)', ($mail['to'] ?? '') === 'pham.van.a@example.invalid' && $n['recipient_email'] === 'pham.van.a@example.invalid');
check($G, 'HTML: căn, kỳ, tiền điện gồm VAT 868.698 đ, nước 185.120 đ, tổng phải thu 1.950.058 đ', str_contains($mail['html'] ?? '', 'CHUNGCU-A / BT1')
    && str_contains($mail['html'], 'Kỳ 06/2024') && str_contains($mail['html'], '868.698 đ') && str_contains($mail['html'], '185.120 đ') && str_contains($mail['html'], '1.950.058 đ'));
check($G, 'có bản plain-text fallback đủ số liệu', str_contains($mail['text'] ?? '', 'TỔNG PHẢI THU: 1.950.058 đ') && !preg_match('/<(table|td|tr|div|p|strong)\b/i', $mail['text']));
check($G, 'tên người nhận được escape trong HTML (không chèn được thẻ)', str_contains($mail['html'], 'Phạm Văn A &lt;script&gt;') && !str_contains($mail['html'], '<script>'));
check($G, 'KHÔNG gửi số điện thoại / dữ liệu cư dân khác', !str_contains($mail['html'] . $mail['text'], '0901234567') && !str_contains($mail['html'] . $mail['text'], 'nguyen.thi.b'));
check($G, 'bản lưu body_html / body_text = nội dung đã gửi; content_hash 64 ký tự', $n['body_html'] === $mail['html'] && $n['body_text'] === $mail['text'] && strlen($n['content_hash']) === 64);
$img = (array) config('email_images');
$qrPath = BASE_PATH . '/' . $img['payment-qr'];
$headerPath = BASE_PATH . '/' . $img['header-visual'];
check($G, 'Khối QR: "Quét QR để thanh toán", chủ TK NGUYEN HONG PHUONG, số TK 941*****999, ngân hàng MB (HTML + plain-text)',
    str_contains($mail['html'], 'Quét QR để thanh toán') && str_contains($mail['html'], 'NGUYEN HONG PHUONG') && str_contains($mail['html'], '941*****999') && str_contains($mail['html'], '>MB<')
    && str_contains($mail['text'], 'Quét QR để thanh toán') && str_contains($mail['text'], 'Chủ tài khoản: NGUYEN HONG PHUONG') && str_contains($mail['text'], 'Số tài khoản: 941*****999') && str_contains($mail['text'], 'Ngân hàng: MB'));
check($G, 'Ảnh nhúng bằng cid (Gmail): QR + hình đầu thư, gửi kèm đúng file, không dùng ảnh data:',
    str_contains($mail['html'], 'src="cid:payment-qr"') && str_contains($mail['html'], 'src="cid:header-visual"') && !str_contains($mail['html'], 'data:image')
    && ($mail['inline'] ?? []) === ['payment-qr' => $qrPath, 'header-visual' => $headerPath] && is_file($qrPath) && is_file($headerPath));
check($G, 'Không còn câu "chưa cấu hình" / "chưa được đặc tả" trong email', !preg_match('/chưa cấu hình|chưa được đặc tả/iu', $mail['html'] . $mail['text']));
check($G, 'không đổi trạng thái / issued_at / updated_at bản tính và trạng thái kỳ (không "phát hành")', q($pdo, 'SELECT CONCAT(c.status, IFNULL(c.issued_at, ""), c.updated_at, p.status) FROM apartment_charges c JOIN billing_periods p ON p.id = c.billing_period_id WHERE c.id = 1') === $stateBefore);
$a = $pdo->query("SELECT new_value FROM audit_logs WHERE action = 'SEND_EMAIL_NOTIFICATION' ORDER BY id DESC LIMIT 1")->fetchColumn();
check($G, 'audit SEND_EMAIL_NOTIFICATION: email đã che, không có mật khẩu', is_string($a) && str_contains($a, 'ph••••••••@example.invalid') && !str_contains($a, 'SECRETAPPPW') && !str_contains($a, 'pham.van.a@'));

// =========================================================== Chống trùng
$G = 'DEDUPE';
expectError($G, 'bấm gửi lần 2 (cùng nội dung, cùng người nhận) -> "Không gửi trùng"', fn () => $svc->sendBillNotice(CHARGE, '1'), 'Không gửi trùng');
check($G, 'vẫn 1 bản ghi, 1 thư', (int) q($pdo, 'SELECT COUNT(*) FROM email_notifications') === 1 && count(EmailService::$sent) === 1);
$second = (int) q($pdo, "SELECT id FROM residents WHERE full_name = 'Email hỏng'");
$pdo->exec("UPDATE residents SET email = 'tenant.two@example.invalid' WHERE id = {$second}");
$r2 = $svc->sendBillNotice(CHARGE, (string) $second);
check($G, 'người nhận khác cùng phiếu -> gửi được (bản ghi riêng)', $r2['status'] === 'sent' && (int) q($pdo, 'SELECT COUNT(*) FROM email_notifications') === 2);
$pdo->exec('UPDATE apartment_charges SET old_debt_amount = 100000, total_due = total_due + 100000 WHERE id = 1');
$r3 = $svc->sendBillNotice(CHARGE, '1');
check($G, 'nội dung phiếu đổi (nợ cũ đổi) -> được gửi thông báo mới (A-EMAIL-07)', $r3['status'] === 'sent' && (int) q($pdo, 'SELECT COUNT(*) FROM email_notifications WHERE apartment_charge_id = 1 AND recipient_email = ?', ['pham.van.a@example.invalid']) === 2
    && str_contains(end(EmailService::$sent)['html'], '2.050.058 đ'));
$pdo->exec('UPDATE apartment_charges SET old_debt_amount = 0, total_due = total_due - 100000 WHERE id = 1');

// =========================================================== Lỗi gửi + gửi lại
$G = 'FAIL-RETRY';
$pdo->exec("DELETE FROM email_notifications WHERE recipient_email = 'tenant.two@example.invalid'");
EmailService::fail('SMTP Error: Could not authenticate. user=sender.test@example.invalid pass=SECRETAPPPW1234');
$f = $svc->sendBillNotice(CHARGE, (string) $second);
$fn = row($pdo, $f['id']);
check($G, 'gửi lỗi -> status failed, last_error có nội dung', $f['status'] === 'failed' && $fn['status'] === 'failed' && $fn['last_error'] !== null && $fn['sent_at'] === null);
check($G, 'last_error đã làm sạch: không có App Password / username', !str_contains($fn['last_error'], 'SECRETAPPPW') && !str_contains($fn['last_error'], 'sender.test@'), $fn['last_error']);
$fa = $pdo->query("SELECT new_value FROM audit_logs WHERE action = 'EMAIL_NOTIFICATION_FAILED' ORDER BY id DESC LIMIT 1")->fetchColumn();
check($G, 'audit EMAIL_NOTIFICATION_FAILED, không chứa mật khẩu', is_string($fa) && !str_contains($fa, 'SECRETAPPPW'));
expectError($G, 'gửi lại từ màn phiếu khi đã có bản lỗi -> hướng dẫn dùng "Gửi lại"', fn () => $svc->sendBillNotice(CHARGE, (string) $second), 'Gửi lại');
$rr = $svc->retry($f['id']);
$fn = row($pdo, $f['id']);
check($G, 'retry bản lỗi -> sent, attempt_count 2, xoá last_error', $rr['status'] === 'sent' && $fn['status'] === 'sent' && (int) $fn['attempt_count'] === 2 && $fn['last_error'] === null);
check($G, 'audit RETRY_EMAIL_NOTIFICATION + SEND_EMAIL_NOTIFICATION', (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE action = 'RETRY_EMAIL_NOTIFICATION' AND entity_id = ?", [(string) $f['id']]) === 1
    && (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE action = 'SEND_EMAIL_NOTIFICATION' AND entity_id = ?", [(string) $f['id']]) === 1);
expectError($G, 'retry bản đã gửi -> từ chối', fn () => $svc->retry($f['id']), 'không gửi lại');
$pdo->exec("UPDATE email_notifications SET status = 'pending' WHERE id = {$f['id']}");
expectError($G, 'retry bản đang gửi (pending mới) -> từ chối', fn () => $svc->retry($f['id']), 'đang được gửi');
$pdo->exec("UPDATE email_notifications SET status = 'pending', updated_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = {$f['id']}");
check($G, 'retry bản pending kẹt > 10 phút -> cho gửi lại', $svc->retry($f['id'])['status'] === 'sent');
$pdo->exec("UPDATE email_notifications SET status = 'failed' WHERE id = {$f['id']}");
$pdo->exec('UPDATE apartment_charges SET old_debt_amount = 5000, total_due = total_due + 5000 WHERE id = 1');
expectError($G, 'retry khi số liệu phiếu đã đổi -> từ chối, yêu cầu gửi thông báo mới', fn () => $svc->retry($f['id']), 'đã thay đổi');
$pdo->exec('UPDATE apartment_charges SET old_debt_amount = 0, total_due = total_due - 5000 WHERE id = 1');
expectError($G, 'retry id không tồn tại', fn () => $svc->retry(999999), 'không tồn tại');

// =========================================================== Transport
$G = 'TRANSPORT';
$mailer = new EmailService();
foreach (['to có CR/LF' => ["a@b.com\r\nBcc: c@d.com", 'X', 'Tiêu đề'], 'tiêu đề có CR/LF' => ['a@b.com', 'X', "Tiêu đề\r\nBcc: c@d.com"], 'tên có CR/LF' => ['a@b.com', "X\nBcc: c@d.com", 'T'], 'to sai định dạng' => ['not-an-email', 'X', 'T']] as $label => [$to, $name, $subject]) {
    try {
        $mailer->send($to, $name, $subject, '<p>x</p>', 'x');
        check($G, "chống header injection: {$label} -> từ chối", false);
    } catch (EmailTransportException $e) {
        check($G, "chống header injection: {$label} -> từ chối", true);
    }
}
check($G, 'sanitize bỏ mật khẩu, username, xuống dòng; giới hạn 500 ký tự', EmailService::sanitize("AUTH LOGIN\r\nfail SECRETAPPPW1234 for sender.test@example.invalid " . str_repeat('x', 900)) !== ''
    && !str_contains(EmailService::sanitize('pw SECRETAPPPW1234 user sender.test@example.invalid'), 'SECRETAPPPW') && !str_contains(EmailService::sanitize("a\r\nb"), "\n")
    && mb_strlen(EmailService::sanitize(str_repeat('y', 900))) === 500);
$pdo->exec("UPDATE residents SET email = 'le.thi.d@example.invalid' WHERE id = 4");
Config::set('mail.driver', 'smtp');
Config::set('mail.password', '');
$x = $svc->sendBillNotice(4, '4');
check($G, 'driver smtp thiếu MAIL_PASSWORD -> failed "Chưa cấu hình SMTP", không exception', $x['status'] === 'failed' && str_contains((string) $x['error'], 'Chưa cấu hình SMTP'), (string) $x['error']);
Config::set('mail.host', '127.0.0.1');
Config::set('mail.port', 1);
Config::set('mail.timeout', 5);
Config::set('mail.password', 'SECRETAPPPW1234');
$t0 = microtime(true);
$y = $svc->retry($x['id']);
check($G, 'driver smtp (PHPMailer thật) tới cổng đóng 127.0.0.1:1 -> failed, không treo, lỗi đã làm sạch', $y['status'] === 'failed' && microtime(true) - $t0 < 20
    && !str_contains((string) $y['error'], 'SECRETAPPPW') && !str_contains((string) $y['error'], 'sender.test@'), (string) $y['error']);
check($G, 'không có mật khẩu trong toàn bộ email_notifications + audit_logs', (int) q($pdo, "SELECT COUNT(*) FROM email_notifications WHERE CONCAT(IFNULL(last_error,''), body_html, body_text) LIKE '%SECRETAPPPW%'") === 0
    && (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE CONCAT(IFNULL(old_value,''), IFNULL(new_value,'')) LIKE '%SECRETAPPPW%'") === 0);
Config::set('mail.driver', 'array');

// =========================================================== Tra cứu hộp thư đi
$G = 'SEARCH';
$all = $svc->search(['status' => '', 'date_from' => '', 'date_to' => '', 'recipient' => '', 'apartment' => '', 'period_id' => ''], 1);
check($G, 'liệt kê tất cả, mới nhất trước', $all['total'] === (int) q($pdo, 'SELECT COUNT(*) FROM email_notifications') && $all['data'][0]['id'] > end($all['data'])['id']);
check($G, 'lọc trạng thái failed', array_unique(array_column($svc->search(['status' => 'failed'], 1)['data'], 'status')) === ['failed']);
check($G, 'lọc người nhận (một phần email) + căn hộ', $svc->search(['recipient' => 'pham.van', 'apartment' => 'BT1'], 1)['total'] === 2 && $svc->search(['apartment' => 'S01'], 1)['total'] === 0);
check($G, 'lọc khoảng ngày hôm nay', $svc->search(['date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d')], 1)['total'] === $all['total']);
foreach ([['status' => 'deleted'], ['date_from' => '2026-13-40'], ['date_from' => '2026-05-01', 'date_to' => '2026-01-01']] as $bad) {
    expectError($G, 'bộ lọc sai ' . json_encode($bad) . ' -> lỗi', fn () => $svc->search($bad, 1));
}

// =========================================================== Đồng thời (tiến trình PHP thật)
$G = 'CONCURRENCY';
$pdo->exec("DELETE FROM email_notifications WHERE apartment_charge_id = 3");
$pdo->exec("UPDATE residents SET email = 'tran.van.c@example.invalid' WHERE id = 3");
$out = race($testDb, [[KTO, 'ke_toan', 'send', 3, '3'], [BQL, 'bql', 'send', 3, '3'], [KTO, 'ke_toan', 'send', 3, '3']]);
check($G, '3 người bấm gửi cùng phiếu + người nhận cùng lúc -> đúng 1 bản ghi, 1 gửi thành công', (int) q($pdo, 'SELECT COUNT(*) FROM email_notifications WHERE apartment_charge_id = 3') === 1
    && count(array_filter($out, static fn ($o) => $o['ok'])) === 1 && !str_contains(json_encode($out), 'EXCEPTION'), json_encode($out, JSON_UNESCAPED_UNICODE));
$fid = (int) q($pdo, 'SELECT id FROM email_notifications WHERE apartment_charge_id = 3');
$pdo->exec("UPDATE email_notifications SET status = 'failed', last_error = 'x' WHERE id = {$fid}");
$out = race($testDb, [[KTO, 'ke_toan', 'retry', $fid], [BQL, 'bql', 'retry', $fid]]);
check($G, '2 người bấm "Gửi lại" cùng lúc -> đúng 1 lần gửi lại (attempt_count +1)', count(array_filter($out, static fn ($o) => $o['ok'])) === 1
    && (int) q($pdo, 'SELECT attempt_count FROM email_notifications WHERE id = ?', [$fid]) === 2 && q($pdo, 'SELECT status FROM email_notifications WHERE id = ?', [$fid]) === 'sent', json_encode($out, JSON_UNESCAPED_UNICODE));

// =========================================================== Chung
$G = 'GENERAL';
foreach (['SEND_EMAIL_NOTIFICATION', 'EMAIL_NOTIFICATION_FAILED', 'RETRY_EMAIL_NOTIFICATION'] as $action) {
    check($G, "có audit {$action}", (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]) > 0);
}
$src = implode('', array_map(static fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
    token_get_all((string) file_get_contents(dirname(__DIR__) . '/app/Controllers/EmailNotificationController.php'))));
check($G, 'Controller không gọi SMTP / PHPMailer / EmailService trực tiếp', !preg_match('/PHPMailer|EmailService|SMTP/', $src));
check($G, 'không có credential trong mã nguồn / config mẫu', !preg_match('/MAIL_PASSWORD=\S/', (string) file_get_contents(dirname(__DIR__) . '/.env.example'))
    && !preg_match("/'password'\s*=>\s*'[^']+'/", (string) file_get_contents(dirname(__DIR__) . '/config/mail.php')));

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
