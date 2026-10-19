<?php

declare(strict_types=1);

/**
 * STEP 25 - Kiểm tra gửi email THẬT một lần (thủ công / tùy chọn). KHÔNG chạy nếu thiếu cấu hình:
 *   MAIL_DRIVER=smtp, MAIL_USERNAME, MAIL_PASSWORD (App Password), MAIL_TEST_RECIPIENT (hộp thư test của developer) trong .env.
 *   php tests/support/gmail_smoke.php
 * Luồng đầy đủ trên DB của .env: cư dân tạm (email = MAIL_TEST_RECIPIENT) gắn căn BT1 → EmailNotificationService gửi phiếu
 * bản tính #1 → kiểm tra status = sent → xoá cư dân tạm (bản ghi hộp thư đi giữ lại làm bằng chứng, resident_id → NULL).
 * Không in mật khẩu; địa chỉ nhận chỉ in dạng đã che. Thiếu cấu hình = SKIPPED (exit 0), KHÔNG phải lỗi của code.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();

$cfg = config('mail');
$missing = [];
if ($cfg['driver'] !== 'smtp') {
    $missing[] = 'MAIL_DRIVER=smtp';
}
foreach (['username' => 'MAIL_USERNAME', 'password' => 'MAIL_PASSWORD', 'test_recipient' => 'MAIL_TEST_RECIPIENT'] as $k => $env) {
    if (trim((string) $cfg[$k]) === '') {
        $missing[] = $env;
    }
}
if ($missing !== []) {
    echo 'SKIPPED gửi Gmail thật: chưa cấu hình ' . implode(', ', $missing) . " trong .env (không phải lỗi của code).\n";
    exit(0);
}
$to = strtolower(trim((string) $cfg['test_recipient']));
if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    echo "FAIL MAIL_TEST_RECIPIENT không phải email hợp lệ.\n";
    exit(1);
}

$pdo = App\Core\Database::connection();
$_SESSION = ['user_id' => 3, 'user_role' => 'ke_toan'];
$_SERVER['REMOTE_ADDR'] = 'gmail-smoke';
$pdo->prepare("INSERT INTO residents (apartment_id, full_name, resident_role, email, is_active, effective_from) VALUES (1, 'Người nhận thử nghiệm (smoke)', 'khach_thue', ?, 1, '2024-01-01')")->execute([$to]);
$rid = (int) $pdo->lastInsertId();
$ok = false;
try {
    $r = (new App\Services\EmailNotificationService())->sendBillNotice(1, (string) $rid);
    $row = $pdo->query('SELECT status, subject, transport, sent_at FROM email_notifications WHERE id = ' . (int) $r['id'])->fetch();
    $ok = $r['status'] === 'sent' && $row['status'] === 'sent' && $row['transport'] === 'smtp';
    echo ($ok ? 'PASS' : 'FAIL') . " gửi Gmail thật tới " . mask_email($to) . " qua {$cfg['host']}:{$cfg['port']} ({$cfg['encryption']}) — status={$row['status']}"
        . ($r['error'] ? ' lỗi=' . $r['error'] : '') . "\n";
    echo "     subject: {$row['subject']}\n     → mở hộp thư nhận để xác nhận tiêu đề / nội dung tiếng Việt hiển thị đúng.\n";
} catch (App\Core\ValidationException $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
} finally {
    $pdo->prepare('DELETE FROM residents WHERE id = ?')->execute([$rid]);
}
exit($ok ? 0 : 1);
