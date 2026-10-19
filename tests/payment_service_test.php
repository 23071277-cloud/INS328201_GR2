<?php

declare(strict_types=1);

/**
 * Payment module - test cấp Service trên DB TẠM (KHÔNG gửi email thật: driver "array").
 *   php tests/payment_service_test.php <db_tạm>
 * Seed: kỳ 06/2024 (id 1) ĐÃ KHOÁ, 4 bản tính id 1–4 (BT1 1.950.058 đ, S01, CC-2305, CC-2306); kỳ 07/2024 (id 2) mở.
 * Kiểm: mã thanh toán, khớp đúng số tiền, idempotency (callback trùng, đồng thời), sai số tiền / không rõ mã / kỳ chưa khoá / trả trùng,
 * khớp thủ công / bỏ qua, email xác nhận chỉ 1 lần + gửi lại thư lỗi, lọc loại email (phiếu phí hàng loạt), HMAC, mở lại kỳ (cảnh báo +
 * xác nhận), trạng thái tính từ giao dịch, KHÔNG ghi apartment_charges, ẩn chức năng khi DB chưa có bảng.
 */

$testDb = $argv[1] ?? '';
if ($testDb === '' || in_array($testDb, ['energy_dss', 'energy_dss_v2'], true)) {
    fwrite(STDERR, "Cần tên database TẠM.\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';
$_ENV['DB_DATABASE'] = $testDb;
restore_error_handler();
restore_exception_handler();

use App\Core\Config;
use App\Core\Database;
use App\Core\ValidationException;
use App\Repositories\PaymentTransactionRepository;
use App\Services\EmailNotificationService;
use App\Services\EmailService;
use App\Services\PaymentCallbackException;
use App\Services\PaymentReconciliationService;
use App\Services\PaymentStatusService;
use App\Services\PeriodBillEmailService;
use App\Services\PeriodClosingService;

$pdo = Database::connection();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
Config::set('mail.driver', 'array');
$_SERVER['REMOTE_ADDR'] = 'payment-service-test';

$pass = 0;
$fail = 0;
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS ' : '  FAIL ') . str_pad($g, 9) . " {$n}" . ($ok || $d === '' ? '' : "  -> {$d}") . "\n";
}
function errorOf(callable $fn): string
{
    try {
        $fn();

        return '';
    } catch (ValidationException $e) {
        return implode(' ', array_merge(...array_values($e->errors())));
    } catch (PaymentCallbackException $e) {
        return $e->httpStatus() . ' ' . $e->getMessage();
    }
}
function actAs(int $id, string $role): void
{
    $_SESSION = ['user_id' => $id, 'user_role' => $role, 'username' => $role, 'full_name' => $role];
}
$q = static fn (string $sql) => $pdo->query($sql)->fetchColumn();
$charge = static fn (int $id): array => $pdo->query("SELECT id, total_due FROM apartment_charges WHERE id = {$id}")->fetch();
$chargesChecksum = static fn (): string => (string) $pdo->query('CHECKSUM TABLE apartment_charges')->fetch()['Checksum'];

$st = new PaymentStatusService();
$svc = new PaymentReconciliationService();
$pdo->exec("UPDATE residents SET email = 'pham.van.a@example.invalid' WHERE apartment_id = 1");
$pdo->exec("UPDATE residents SET email = 'tran.van.c@example.invalid' WHERE apartment_id = 3");
$due1 = (string) $q('SELECT total_due FROM apartment_charges WHERE id = 1');
$due2 = (string) $q('SELECT total_due FROM apartment_charges WHERE id = 2');
$due3 = (string) $q('SELECT total_due FROM apartment_charges WHERE id = 3');
$due4 = (string) $q('SELECT total_due FROM apartment_charges WHERE id = 4');
$checksumBefore = $chargesChecksum();
$auditBefore = (int) $q("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'PAYMENT%'");

// ---------------------------------------------------------------- 1. nền tảng
$G = 'BASE';
check($G, 'Bảng payment_transactions có trên DB tạm (canonical 27 bảng) → chức năng bật', $st->available());
check($G, 'Mã thanh toán EDSS + 6 chữ số: ref(1) = EDSS000001', PaymentStatusService::ref(1) === 'EDSS000001');
check($G, 'Đọc mã trong nội dung: "TT edss-000001 bt1", "EDSS 1" → 1; "XEDSS000001", "EDSS", "edss000000" → null',
    PaymentStatusService::parseRef('TT edss-000001 bt1') === 1 && PaymentStatusService::parseRef('EDSS 1') === 1
    && PaymentStatusService::parseRef('XEDSS000001') === null && PaymentStatusService::parseRef('EDSS') === null && PaymentStatusService::parseRef('edss000000') === null);
check($G, 'Ban đầu cả 4 bản tính kỳ 06/2024: Chưa thanh toán', array_unique(array_column($st->periodSummary(1)['rows'], 'status')) === [PaymentStatusService::UNPAID]);
check($G, 'BT1 phải thu 1.950.058 đ (số tiền bản tính không đổi)', $due1 === '1950058.00', $due1);

// ---------------------------------------------------------------- 2. khớp tự động + idempotency
$G = 'MATCH';
EmailService::$sent = [];
$r = $svc->receive(['transaction_id' => 'TX-001', 'amount' => '1950058', 'content' => 'Thanh toan EDSS000001 can BT1', 'payer_name' => 'Pham Van A']);
check($G, 'Đúng mã + đúng số tiền → matched, chuyển Đã thanh toán', $r['match_status'] === 'matched' && $r['became_paid'] && $st->forCharge($charge(1))['status'] === PaymentStatusService::PAID);
check($G, 'Email xác nhận gửi đúng 1 thư (array, KHÔNG gửi thật) tới cư dân BT1, loại payment_confirmation',
    count($r['emails']) === 1 && count(EmailService::$sent) === 1 && EmailService::$sent[0]['to'] === 'pham.van.a@example.invalid'
    && $q("SELECT notification_type FROM email_notifications WHERE apartment_charge_id = 1 ORDER BY id DESC LIMIT 1") === 'payment_confirmation');
$mail = EmailService::$sent[0] ?? ['html' => '', 'text' => '', 'subject' => '', 'inline' => []];
check($G, 'Nội dung email: tiêu đề, số tiền 1.950.058 đ, mã giao dịch, mã thanh toán; ảnh đầu thư nhúng cid; không QR',
    $mail['subject'] === '[Energy DSS] Xác nhận thanh toán kỳ 06/2024 - Căn BT1' && str_contains($mail['html'], '1.950.058 đ') && str_contains($mail['html'], 'TX-001')
    && str_contains($mail['text'], 'Mã thanh toán: EDSS000001') && array_keys($mail['inline'] ?? []) === ['header-visual'] && !str_contains($mail['html'], 'cid:payment-qr'));
$r2 = $svc->receive(['transaction_id' => 'TX-001', 'amount' => '1950058', 'content' => 'Thanh toan EDSS000001 can BT1']);
check($G, 'Callback trùng transaction_id → duplicate, không ghi thêm, không email, không audit',
    $r2['duplicate'] && $r2['match_status'] === 'matched' && (int) $q("SELECT COUNT(*) FROM payment_transactions WHERE transaction_id = 'TX-001'") === 1
    && count(EmailService::$sent) === 1 && (int) $q("SELECT COUNT(*) FROM audit_logs WHERE action = 'PAYMENT_RECEIVED'") === $auditBefore + 1);
check($G, 'Audit PAYMENT_RECEIVED: match_status, bản tính, mã thanh toán', str_contains((string) $q("SELECT new_value FROM audit_logs WHERE action = 'PAYMENT_RECEIVED' ORDER BY id DESC LIMIT 1"), '"payment_ref":"EDSS000001"'));
check($G, 'Gọi lại sendPaymentConfirmation cho bản tính đã có thư → không gửi thêm (chỉ 1 lần / bản tính)',
    (new EmailNotificationService())->sendPaymentConfirmation(1, (int) $r['id']) === [] && count(EmailService::$sent) === 1);

// ---------------------------------------------------------------- 3. các trường hợp cần kiểm tra
$G = 'REVIEW';
$r3 = $svc->receive(['transaction_id' => 'TX-002', 'amount' => '1950058', 'content' => 'EDSS000001']);
check($G, 'Chuyển trùng (bản tính đã thanh toán) → already_paid; BT1 chuyển Cần kiểm tra; không email', $r3['match_status'] === 'already_paid' && !$r3['became_paid']
    && $st->forCharge($charge(1))['status'] === PaymentStatusService::REVIEW && count(EmailService::$sent) === 1);
$r4 = $svc->receive(['transaction_id' => 'TX-003', 'amount' => '1000', 'content' => 'EDSS000002']);
$r5 = $svc->receive(['transaction_id' => 'TX-004', 'amount' => bcadd($due2, '1', 2), 'content' => 'EDSS000002']);
check($G, 'Thiếu tiền / thừa tiền → amount_mismatch (không thanh toán một phần); S01 Cần kiểm tra; ghi chú nêu số tiền',
    $r4['match_status'] === 'amount_mismatch' && $r5['match_status'] === 'amount_mismatch' && $st->forCharge($charge(2))['status'] === PaymentStatusService::REVIEW
    && str_contains((string) $q("SELECT match_note FROM payment_transactions WHERE transaction_id = 'TX-003'"), 'khác số phải thu'));
$r6 = $svc->receive(['transaction_id' => 'TX-005', 'amount' => $due3, 'content' => 'chuyen tien phi thang 6']);
$r7 = $svc->receive(['transaction_id' => 'TX-006', 'amount' => '5', 'content' => 'EDSS999999']);
check($G, 'Không có mã / mã không tồn tại → unknown_ref, không gắn bản tính', $r6['match_status'] === 'unknown_ref' && $r6['charge_id'] === null && $r7['match_status'] === 'unknown_ref');
$pdo->exec("INSERT INTO apartment_charges (apartment_id, billing_period_id, status, total_due) VALUES (1, 2, 'da_xac_nhan', 100)");
$openCharge = (int) $pdo->lastInsertId();
$checksumBefore = $chargesChecksum(); // bản tính phụ của kỳ mở là dữ liệu dựng test; từ đây apartment_charges phải giữ nguyên
$r8 = $svc->receive(['transaction_id' => 'TX-007', 'amount' => '100', 'content' => PaymentStatusService::ref($openCharge)]);
check($G, 'Bản tính của kỳ CHƯA khoá → not_payable (số phải thu chưa chốt)', $r8['match_status'] === 'not_payable' && str_contains((string) $q("SELECT match_note FROM payment_transactions WHERE transaction_id = 'TX-007'"), 'chưa khoá'));
check($G, 'Payload sai: mã giao dịch rỗng / ký tự lạ, số tiền ≤ 0 / chữ → lỗi rõ ràng, không ghi',
    errorOf(fn () => $svc->receive(['transaction_id' => '', 'amount' => '1', 'content' => 'x'])) !== '' && errorOf(fn () => $svc->receive(['transaction_id' => 'a b', 'amount' => '1', 'content' => 'x'])) !== ''
    && errorOf(fn () => $svc->receive(['transaction_id' => 'TX-X', 'amount' => '0', 'content' => 'x'])) !== '' && errorOf(fn () => $svc->receive(['transaction_id' => 'TX-Y', 'amount' => 'abc', 'content' => 'x'])) !== ''
    && (int) $q("SELECT COUNT(*) FROM payment_transactions WHERE transaction_id IN ('TX-X','TX-Y')") === 0);
check($G, 'Hàng chờ kiểm tra = 6 giao dịch (trả trùng, 2 sai tiền, 2 không rõ mã, kỳ chưa khoá)', count($st->reviewQueue()) === 6 && $st->countOpenReview() === 6);

// ---------------------------------------------------------------- 4. Kế toán xử lý
$G = 'RESOLVE';
actAs(3, 'ke_toan');
check($G, 'Bỏ qua / khớp thủ công bắt buộc lý do', str_contains(errorOf(fn () => $svc->ignore((int) $r3['id'], '  ')), 'lý do') && str_contains(errorOf(fn () => $svc->matchManually((int) $r6['id'], 'EDSS000003', '')), 'lý do'));
$svc->ignore((int) $r3['id'], 'Đã hoàn tiền khoản chuyển trùng');
check($G, 'Bỏ qua giao dịch trả trùng → ignored (người + lúc + lý do); BT1 trở lại Đã thanh toán',
    $q("SELECT CONCAT(match_status, '|', reviewed_by, '|', review_note) FROM payment_transactions WHERE id = {$r3['id']}") === 'ignored|3|Đã hoàn tiền khoản chuyển trùng'
    && $st->forCharge($charge(1))['status'] === PaymentStatusService::PAID);
check($G, 'Xử lý lại giao dịch đã xử lý → bị từ chối', str_contains(errorOf(fn () => $svc->ignore((int) $r3['id'], 'lần 2')), 'đã được xử lý'));
check($G, 'Khớp thủ công sai số tiền / vào bản tính kỳ chưa khoá / mã sai → bị từ chối',
    str_contains(errorOf(fn () => $svc->matchManually((int) $r4['id'], 'EDSS000002', 'thử')), 'không hỗ trợ thanh toán một phần')
    && str_contains(errorOf(fn () => $svc->matchManually((int) $r6['id'], PaymentStatusService::ref($openCharge), 'thử')), 'kỳ phải đã khoá')
    && str_contains(errorOf(fn () => $svc->matchManually((int) $r6['id'], 'abc', 'thử')), 'không hợp lệ'));
EmailService::$sent = [];
$m = $svc->matchManually((int) $r6['id'], 'edss 3', 'Chủ hộ CC-2305 xác nhận khoản chuyển không ghi mã');
check($G, 'Khớp thủ công đúng số tiền → manual_matched, CC-2305 Đã thanh toán, email xác nhận 1 thư, audit PAYMENT_REVIEW_MATCHED',
    $m['match_status'] === 'manual_matched' && $st->forCharge($charge(3))['status'] === PaymentStatusService::PAID && count(EmailService::$sent) === 1
    && EmailService::$sent[0]['to'] === 'tran.van.c@example.invalid' && (int) $q("SELECT COUNT(*) FROM audit_logs WHERE action = 'PAYMENT_REVIEW_MATCHED'") === 1);

// ---------------------------------------------------------------- 5. email: lọc loại + gửi lại thư lỗi
$G = 'EMAIL';
$report = (new PeriodBillEmailService())->report(1)['count'];
check($G, 'Đếm phiếu phí hàng loạt kỳ 06/2024 KHÔNG tính email xác nhận thanh toán (0 phiếu đã gửi)', $report['sent'] === 0, json_encode($report));
EmailService::$sent = [];
EmailService::fail('Lỗi SMTP giả lập');
$pdo->exec("UPDATE residents SET email = 'le.thi.d@example.invalid' WHERE apartment_id = 4");
$r9 = $svc->receive(['transaction_id' => 'TX-008', 'amount' => $due4, 'content' => 'EDSS000004']);
$failedId = (int) $q("SELECT id FROM email_notifications WHERE apartment_charge_id = 4 AND notification_type = 'payment_confirmation'");
check($G, 'Gửi email lỗi KHÔNG làm hỏng thanh toán: giao dịch matched, CC-2306 Đã thanh toán, thư ở trạng thái failed',
    $r9['match_status'] === 'matched' && $st->forCharge($charge(4))['status'] === PaymentStatusService::PAID && $q("SELECT status FROM email_notifications WHERE id = {$failedId}") === 'failed');
actAs(3, 'ke_toan');
$retry = (new EmailNotificationService())->retry($failedId);
check($G, 'Gửi lại thư xác nhận lỗi → sent (không so lại nội dung phiếu phí - loại payment_confirmation)', $retry['status'] === 'sent' && count(EmailService::$sent) === 1);
check($G, 'Gửi lại hàng loạt phiếu phí của kỳ không đụng thư xác nhận thanh toán', (new PeriodBillEmailService())->retryFailed(1)['retried'] === 0);

// ---------------------------------------------------------------- 6. HMAC callback
$G = 'HMAC';
$body = (string) json_encode(['transaction_id' => 'TX-H1', 'amount' => '1', 'content' => 'EDSS000002']);
Config::set('payment.mock_bank.enabled', false);
check($G, 'Mock Bank tắt (mặc định) → 404, không ghi', str_starts_with(errorOf(fn () => $svc->handleCallback($body, 'x')), '404'));
Config::set('payment.mock_bank.enabled', true);
Config::set('payment.mock_bank.secret', 'short');
check($G, 'Bật nhưng secret < 16 ký tự → vẫn coi là tắt (404)', str_starts_with(errorOf(fn () => $svc->handleCallback($body, 'x')), '404'));
Config::set('payment.mock_bank.secret', 'test-secret-0123456789');
check($G, 'Chữ ký sai / thiếu → 401, không ghi', str_starts_with(errorOf(fn () => $svc->handleCallback($body, str_repeat('0', 64))), '401')
    && str_starts_with(errorOf(fn () => $svc->handleCallback($body, '')), '401') && (int) $q("SELECT COUNT(*) FROM payment_transactions WHERE transaction_id = 'TX-H1'") === 0);
check($G, 'Body bị sửa sau khi ký → 401', str_starts_with(errorOf(fn () => $svc->handleCallback(str_replace('"1"', '"2"', $body), PaymentReconciliationService::sign($body))), '401'));
$h = $svc->handleCallback($body, PaymentReconciliationService::sign($body));
check($G, 'Chữ ký đúng → xử lý (TX-H1 sai số tiền → amount_mismatch)', $h['match_status'] === 'amount_mismatch' && !$h['duplicate']);
check($G, 'JSON hỏng (ký đúng) → 400', str_starts_with(errorOf(fn () => $svc->handleCallback('{x', PaymentReconciliationService::sign('{x'))), '400'));

// ---------------------------------------------------------------- 7. đồng thời
$G = 'CONCUR';
$pdo->exec("DELETE FROM payment_transactions WHERE apartment_charge_id = 2");
$run = static function (array $jobs) use ($testDb): array {
    $start = microtime(true) + 1.5;
    $procs = [];
    foreach ($jobs as $j) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/payment_worker.php') . ' ' . escapeshellarg($testDb) . ' '
            . implode(' ', array_map('escapeshellarg', $j)) . ' ' . escapeshellarg((string) $start);
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes) ?: null;
        $procs[count($procs) - 1] = [$procs[count($procs) - 1], $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $out[] = json_decode((string) stream_get_contents($pipes[1]), true) ?? ['ok' => false, 'message' => (string) stream_get_contents($pipes[2])];
        proc_close($p);
    }

    return $out;
};
$o = $run([['TX-C1', $due2, 'EDSS000002'], ['TX-C1', $due2, 'EDSS000002']]);
check($G, '2 callback CÙNG transaction_id đồng thời → đúng 1 bản ghi, 1 matched + 1 duplicate', (int) $q("SELECT COUNT(*) FROM payment_transactions WHERE transaction_id = 'TX-C1'") === 1
    && count(array_filter($o, static fn ($x) => ($x['ok'] ?? false) && !$x['duplicate'])) === 1 && count(array_filter($o, static fn ($x) => ($x['duplicate'] ?? false))) === 1, json_encode($o));
$pdo->exec("DELETE FROM payment_transactions WHERE apartment_charge_id = 2");
$o = $run([['TX-C2', $due2, 'EDSS000002'], ['TX-C3', $due2, 'EDSS000002']]);
$st2 = array_column($o, 'match_status');
sort($st2);
check($G, '2 giao dịch KHÁC mã cùng bản tính đồng thời → 1 matched + 1 already_paid (khoá bản tính, không ghi nhận đôi)', $st2 === ['already_paid', 'matched'], json_encode($o));

// ---------------------------------------------------------------- 8. dashboard + mở lại kỳ
$G = 'SUMMARY';
$pdo->exec("DELETE FROM payment_transactions WHERE apartment_charge_id = 2 AND match_status = 'already_paid'");
$s = $st->periodSummary(1);
$sumDue = bcadd(bcadd($due1, $due2, 2), bcadd($due3, $due4, 2), 2);
check($G, 'Tổng phải thu = Σ 4 bản tính; đã thu = Σ 4 (đều Đã thanh toán); chưa thu 0', $s['totals']['due'] === $sumDue && $s['totals']['collected'] === $sumDue
    && $s['totals']['outstanding'] === '0.00' && $s['counts'][PaymentStatusService::PAID] === 4, json_encode($s['totals']));
$G = 'REOPEN';
actAs(1, 'bql');
check($G, 'Mở lại kỳ đã có thanh toán KHÔNG xác nhận → yêu cầu tích xác nhận (không chặn cứng)', str_contains(errorOf(fn () => (new PeriodClosingService())->reopen(1, 'Sửa chỉ số')), 'tích xác nhận')
    && $q('SELECT status FROM billing_periods WHERE id = 1') === 'da_khoa');
$warn = (new PeriodClosingService())->reopen(1, 'Sửa chỉ số', true);
check($G, 'Có xác nhận → mở lại được + cảnh báo thanh toán (4 căn)', $q('SELECT status FROM billing_periods WHERE id = 1') === 'mo_lai'
    && count(array_filter($warn, static fn ($w) => str_contains($w, 'CẢNH BÁO THANH TOÁN: 4 căn'))) === 1, json_encode($warn, JSON_UNESCAPED_UNICODE));
$r10 = $svc->receive(['transaction_id' => 'TX-009', 'amount' => '1', 'content' => 'EDSS000001']);
check($G, 'Kỳ mở lại: giao dịch mới → not_payable; các bản đã thu vẫn Đã thanh toán khi số phải thu không đổi', $r10['match_status'] === 'not_payable'
    && $st->forCharge($charge(3))['status'] === PaymentStatusService::PAID);
check($G, 'Bản tính tính lại (giả lập số phải thu đổi) → Cần kiểm tra (đã thu ≠ phải thu); kỳ không có thanh toán mở lại không cần xác nhận',
    (function () use ($pdo, $st, $charge): bool {
        $updatedAt = (string) $pdo->query('SELECT updated_at FROM apartment_charges WHERE id = 3')->fetchColumn();
        $pdo->exec('UPDATE apartment_charges SET total_due = total_due + 1000 WHERE id = 3');
        $ok = $st->forCharge($charge(3))['status'] === PaymentStatusService::REVIEW;
        $pdo->prepare('UPDATE apartment_charges SET total_due = total_due - 1000, updated_at = ? WHERE id = 3')->execute([$updatedAt]); // khôi phục nguyên trạng

        return $ok;
    })() && (new PaymentStatusService())->recordedForPeriod(2)['charges'] === 0);

// ---------------------------------------------------------------- 9. bất biến + DB chưa migrate
$G = 'INVARIANT';
check($G, 'apartment_charges KHÔNG bị ghi bởi module thanh toán (CHECKSUM giữ nguyên)', $chargesChecksum() === $checksumBefore);
$pdo->exec('RENAME TABLE payment_transactions TO payment_transactions_hidden');
PaymentTransactionRepository::forgetTableCache();
$fresh = new PaymentStatusService();
check($G, 'DB chưa có bảng (vd DB thật trước migrate / rollback v1): chức năng ẩn, phiếu không lỗi, mở lại kỳ không cần xác nhận',
    !$fresh->available() && $fresh->forCharge($charge(1)) === null && $fresh->recordedForPeriod(1) === ['charges' => 0, 'amount' => '0']);
check($G, 'Callback khi DB chưa có bảng → 503', str_starts_with(errorOf(fn () => (new PaymentReconciliationService())->handleCallback($body, PaymentReconciliationService::sign($body))), '503'));
$pdo->exec('RENAME TABLE payment_transactions_hidden TO payment_transactions');
PaymentTransactionRepository::forgetTableCache();

echo "\n{$pass}/" . ($pass + $fail) . ($fail === 0 ? ' PASS' : ' - CÓ LỖI') . " (payment service test, db={$testDb})\n";
exit($fail === 0 ? 0 : 1);
