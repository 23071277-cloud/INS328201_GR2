<?php

declare(strict_types=1);

/**
 * [BA Proposal / Assumption J-16] "Status–Consumption Consistency Verification" - test cấp Service trên DATABASE TẠM.
 *   php tests/j16_service_test.php energy_dss_j16_test     (gọi qua tests/run_j16_tests.sh)
 * Email: driver "array" (fake transport) - KHÔNG gửi Gmail thật; lỗi gửi giả lập bằng EmailService::fail().
 * Kịch bản kỳ 07/2024 (seed: CSĐ = CSC 06/2024):
 *   BT1   (co_o)            điện 343, nước 16     -> FN-07 bình thường, KHÔNG J-16 (case 4); sau đó BQL đổi khai báo -> J-16 "Điện + Nước"
 *   S01   (khong_phat_sinh) điện 0,   nước 0,5    -> J-16 chỉ nước (case 2), gợi ý Thấp
 *   CC-2305 (khong_phat_sinh) điện 0, nước 0      -> không cảnh báo (case 3)
 *   CC-2306 (khong_phat_sinh) điện 25, nước 0     -> J-16 chỉ điện (case 1), gợi ý Trung bình
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
use App\Services\AlertService;
use App\Services\AlertVerificationService;
use App\Services\AlertWorkflowService;
use App\Services\BillingCalculationService;
use App\Services\BillingConfirmationService;
use App\Services\EmailNotificationService;
use App\Services\EmailService;
use App\Services\MeterReadingService;
use App\Services\NoUsageAlertWorkflowService;
use App\Services\NoUsageConsumptionService;
use App\Services\ReadingApprovalService;

Config::set('mail.driver', 'array'); // fake transport - không bao giờ gọi Gmail thật trong test tự động
require_once __DIR__ . '/support/legacy_compat.php'; // STEP 4C: chạy nguyên văn trên schema v1 hoặc v2
$pdo = legacy_compat_install();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
$_SERVER['REMOTE_ADDR'] = 'j16-service-test';
const BQL = 1;
const KT = 2;
const KTO = 3;
const JUL = 2;
const TYPE = 'no_usage_consumption';

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

/** @return string thông báo lỗi ('' nếu không lỗi) */
function errorOf(callable $fn): string
{
    try {
        $fn();

        return '';
    } catch (ValidationException $e) {
        return json_encode($e->errors(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

function alertOf(PDO $pdo, int $apartmentId): ?array
{
    $s = $pdo->prepare('SELECT * FROM alerts WHERE alert_type = ? AND apartment_id = ? AND billing_period_id = ?');
    $s->execute([TYPE, $apartmentId, JUL]);

    return $s->fetch() ?: null;
}

function token(PDO $pdo, int $alertId): string
{
    return AlertService::versionToken($pdo->query('SELECT * FROM alerts WHERE id = ' . $alertId)->fetch());
}

function audits(PDO $pdo, string $action): int
{
    return (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]);
}

function chargeOf(PDO $pdo, int $apartmentId): array
{
    $c = $pdo->query("SELECT * FROM apartment_charges WHERE apartment_id = {$apartmentId} AND billing_period_id = " . JUL)->fetch();

    return $c + ['version_token' => BillingCalculationService::versionToken($c)];
}

// ---------------------------------------------------------------- SETUP
$G = 'SETUP';
$pdo->exec("UPDATE apartments SET occupancy_status = 'khong_phat_sinh' WHERE id IN (2, 3)"); // S01, CC-2305 khai báo không phát sinh (CC-2306 sẵn trong seed)
$pdo->exec("UPDATE residents SET email = 'le.thi.d@example.invalid' WHERE apartment_id = 4");
$pdo->exec("UPDATE residents SET email = 'nguyen.thi.b@example.invalid' WHERE apartment_id = 2");
check($G, 'cấu hình gợi ý ưu tiên j16_* có trong system_configs (seed)', (int) q($pdo, "SELECT COUNT(*) FROM system_configs WHERE config_key LIKE 'j16\\_%'") === 4);
actAs(KT, 'ky_thuat');
$csc = [1 => '1686', 2 => '232', 3 => '650', 4 => '130.5', 5 => '450', 6 => '62', 7 => '25', 8 => '0'];
$entered = 0;
foreach ($csc as $meterId => $value) {
    $entered += errorOf(fn () => (new MeterReadingService())->create(JUL, $meterId, ['current_reading' => $value, 'note' => ''])) === '' ? 1 : 0;
}
check($G, 'Kỹ thuật nhập 8 chỉ số kỳ 07/2024 (Nháp)', $entered === 8);

// ---------------------------------------------------------------- Case 5: Nháp không tạo cảnh báo
$G = 'DETECT';
actAs(BQL, 'bql');
$r = (new NoUsageConsumptionService())->evaluatePeriod(JUL, 'test');
check($G, 'Case 5: chỉ số còn Nháp → không tạo cảnh báo J-16', $r['created'] === [] && (int) q($pdo, 'SELECT COUNT(*) FROM alerts WHERE alert_type = ?', [TYPE]) === 0);

// Duyệt (trigger tự động)
$items = [];
foreach ($pdo->query('SELECT * FROM meter_readings WHERE billing_period_id = ' . JUL)->fetchAll() as $row) {
    $items[(int) $row['id']] = MeterReadingService::versionToken($row);
}
$approved = (new ReadingApprovalService())->approve(JUL, $items);
check($G, 'BQL duyệt 8 chỉ số → trigger J-16 sau khi commit', count($approved['approved']) === 8 && is_array($approved['j16'] ?? null) && count($approved['j16']['created']) === 2,
    json_encode($approved['j16'] ?? null));

$aCc = alertOf($pdo, 4);
$snap = $aCc !== null ? json_decode((string) $aCc['snapshot_json'], true) : [];
check($G, 'Case 1: CC-2306 khai báo không phát sinh + CL điện 25 > 0 → 1 cảnh báo (Mới, hệ thống)', $aCc !== null && $aCc['status'] === 'moi' && $aCc['source'] === 'he_thong'
    && $aCc['alert_rule_id'] === null && $aCc['meter_id'] === null && $aCc['created_by'] === null);
check($G, 'chỉ điện > 0 → utility_type = dien (không NULL)', ($aCc['utility_type'] ?? '') === 'dien' && $aCc['actual_value'] === '25.0000');
check($G, 'snapshot: căn, kỳ, khai báo, CL điện/nước, công tơ + chỉ số, rule J16_NO_USAGE_CONSUMPTION, SYSTEM_RULE, thời điểm',
    ($snap['apartment_code'] ?? '') === 'CC-2306' && ($snap['period_code'] ?? '') === '07/2024' && ($snap['declared_usage_status'] ?? '') === 'khong_phat_sinh'
    && ($snap['electricity_consumption'] ?? '') === '25.00' && ($snap['water_consumption'] ?? '') === '0.00' && count($snap['meters'] ?? []) === 2
    && ($snap['rule'] ?? '') === 'J16_NO_USAGE_CONSUMPTION' && ($snap['source'] ?? '') === 'SYSTEM_RULE' && isset($snap['detected_at'], $snap['meters'][0]['reading_id'], $snap['meters'][0]['confirmed_at']),
    json_encode($snap, JSON_UNESCAPED_UNICODE));
check($G, 'message trung tính đúng mẫu, không chứa "rò điện / gian lận / trái phép / hỏng công tơ"', $aCc['description'] === config('j16.message')
    && !preg_match('/rò điện|gian lận|trái phép|hỏng công tơ/u', $aCc['description']));
check($G, 'gợi ý ưu tiên [J-16-P]: CL điện 25 trong [10, 100) → Trung bình (kèm căn cứ)', $aCc['priority'] === 'trung_binh' && str_contains($snap['priority_suggestion']['basis'] ?? '', 'J-16-P'));
$aS01 = alertOf($pdo, 2);
check($G, 'Case 2: S01 chỉ nước 0,5 > 0 → cảnh báo utility_type = nuoc, gợi ý Thấp (< 1 m³)', $aS01 !== null && $aS01['utility_type'] === 'nuoc' && $aS01['priority'] === 'thap');
check($G, 'Case 3: CC-2305 khai báo không phát sinh, CL điện = nước = 0 → không cảnh báo', alertOf($pdo, 3) === null);
check($G, 'Case 4: BT1 (Có ở) CL > 0 → không cảnh báo J-16', alertOf($pdo, 1) === null);
check($G, 'audit DETECT_NO_USAGE_CONSUMPTION ×2 + lịch sử "Hệ thống phát hiện"', audits($pdo, 'DETECT_NO_USAGE_CONSUMPTION') === 2
    && (int) q($pdo, "SELECT COUNT(*) FROM alert_status_history WHERE alert_id = ? AND note LIKE 'Hệ thống phát hiện%'", [$aCc['id']]) === 1);

$r = (new NoUsageConsumptionService())->evaluatePeriod(JUL, 'test');
$r2 = (new NoUsageConsumptionService())->evaluatePeriod(JUL, 'test');
check($G, 'Case 6: đánh giá lại 2 lần → không tạo trùng, không đổi (2 không đổi)', $r['created'] === [] && $r2['created'] === [] && $r2['unchanged'] === 2
    && (int) q($pdo, 'SELECT COUNT(*) FROM alerts WHERE alert_type = ?', [TYPE]) === 2);
$dup = errorOf(function () use ($pdo): void {
    try {
        $pdo->exec("INSERT INTO alerts (alert_type, apartment_id, billing_period_id, utility_type, source, status) VALUES ('" . TYPE . "', 4, " . JUL . ", 'dien', 'he_thong', 'moi')");
    } catch (PDOException $e) {
        throw ValidationException::withMessage((string) $e->errorInfo[1]);
    }
});
check($G, 'DB chặn trùng: uq_alert_system_dedupe (1062)', str_contains($dup, '1062'));
$util = errorOf(function () use ($pdo): void {
    try {
        $pdo->exec("INSERT INTO alerts (apartment_id, billing_period_id, utility_type, source, status, description) VALUES (1, " . JUL . ", NULL, 'thu_cong', 'moi', 'x')");
    } catch (PDOException $e) {
        throw ValidationException::withMessage((string) $e->errorInfo[1]);
    }
});
check($G, 'FN-07 giữ semantic: cảnh báo thủ công utility_type NULL bị CHECK chặn (4025) — NULL chỉ hợp lệ với J-16', str_contains($util, '4025'));
check($G, 'nhãn tiện ích: J-16 NULL = "Điện + Nước"; FN-07 giữ nhãn cũ', NoUsageConsumptionService::utilityLabel(['alert_type' => TYPE, 'utility_type' => null]) === 'Điện + Nước'
    && NoUsageConsumptionService::utilityLabel(['alert_type' => 'threshold', 'utility_type' => 'nuoc']) === label_of('utility_type', 'nuoc')
    && NoUsageConsumptionService::utilityLabel(['alert_type' => 'threshold', 'utility_type' => null]) !== 'Điện + Nước');
$s = NoUsageConsumptionService::suggestPriority('150', '0', ['j16_low_max_kwh' => '10', 'j16_high_min_kwh' => '100', 'j16_low_max_m3' => '1', 'j16_high_min_m3' => '10']);
$bad = NoUsageConsumptionService::suggestPriority('150', '0', ['j16_low_max_kwh' => 'abc']);
check($G, 'gợi ý: ≥ ngưỡng cao → Cao; cấu hình sai → Trung bình kèm ghi chú (không đoán số)', $s['level'] === 'cao' && $bad['level'] === 'trung_binh' && str_contains($bad['basis'], 'Thiếu / sai cấu hình'));

// ---------------------------------------------------------------- Kế toán: gate
$G = 'BILLING GATE';
actAs(KTO, 'ke_toan');
$calc = errorOf(fn () => (new BillingCalculationService())->calculatePeriod(JUL));
check($G, 'Kế toán vẫn tính nháp cả kỳ khi có cảnh báo J-16 (không chặn tính)', $calc === '' && (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ?', [JUL]) === 4, $calc);
$cc = chargeOf($pdo, 4);
$res = (new BillingConfirmationService())->confirm(JUL, [$cc['id'] => $cc['version_token']]);
check($G, 'Case 11: xác nhận bản tính CC-2306 khi J-16 chưa kết luận → bị chặn với thông báo gate, không đổi số tiền',
    $res['done'] === [] && str_contains($res['skipped'][$cc['id']] ?? '', config('j16.gate_message')) && chargeOf($pdo, 4)['status'] === 'da_tinh' && chargeOf($pdo, 4)['total_due'] === $cc['total_due'],
    json_encode($res, JSON_UNESCAPED_UNICODE));
$bt1 = chargeOf($pdo, 1);
$res = (new BillingConfirmationService())->confirm(JUL, [$bt1['id'] => $bt1['version_token']]);
check($G, 'căn không có J-16 (BT1) xác nhận bình thường', $res['done'] === [(int) $bt1['id']]);
$detail = (new BillingCalculationService())->detail((int) $cc['id']);
check($G, 'chi tiết bản tính có j16_blocking (cảnh báo #' . $aCc['id'] . ') cho cảnh báo / nút bị khoá', ($detail['j16_blocking']['id'] ?? null) == $aCc['id']);

// ---------------------------------------------------------------- BQL review + Kỹ thuật
$G = 'BQL + TECH';
actAs(BQL, 'bql');
$wf = new AlertWorkflowService();
check($G, 'BQL review (đổi ưu tiên Trung bình → Cao) → Đã review, audit REVIEW_NO_USAGE_ALERT', errorOf(fn () => $wf->review((int) $aCc['id'], 'cao', 'BQL xem xét', token($pdo, (int) $aCc['id']))) === ''
    && alertOf($pdo, 4)['status'] === 'da_review' && alertOf($pdo, 4)['priority'] === 'cao' && audits($pdo, 'REVIEW_NO_USAGE_ALERT') === 1);
check($G, 'kết luận trước khi review bị chặn (S01 còn Mới)', str_contains(errorOf(fn () => (new NoUsageAlertWorkflowService())->conclude((int) $aS01['id'], 'real_consumption_confirmed', 'x', '', token($pdo, (int) $aS01['id']))), 'review'));
$ver = new AlertVerificationService();
check($G, 'yêu cầu Kỹ thuật kiểm tra (FN-07.07) → Cần kiểm tra lại, audit REQUEST_TECH_VERIFICATION', errorOf(fn () => $ver->request((int) $aCc['id'], config('j16.tech_reason'), (string) KT, token($pdo, (int) $aCc['id']))) === ''
    && alertOf($pdo, 4)['status'] === 'can_kiem_tra_lai' && audits($pdo, 'REQUEST_TECH_VERIFICATION') === 1);
check($G, 'BQL kết luận A/B/C khi case chưa có kết quả → chặn', str_contains(errorOf(fn () => (new NoUsageAlertWorkflowService())->conclude((int) $aCc['id'], 'real_consumption_confirmed', 'x', '', token($pdo, (int) $aCc['id']))), 'chưa có kết quả'));
actAs(KT, 'ky_thuat');
check($G, 'Kỹ thuật dùng kết luận FN-07 cũ ("canh_bao_dung") cho J-16 → từ chối', str_contains(errorOf(fn () => $ver->submitResult((int) $aCc['id'], 'canh_bao_dung', '', '')), 'result_conclusion'));
check($G, 'Case 8: Kỹ thuật gửi kết quả METER_OK + ghi chú → case Đã có kết quả; cảnh báo vẫn Cần kiểm tra lại (KT không kết luận)',
    errorOf(fn () => $ver->submitResult((int) $aCc['id'], 'cong_to_binh_thuong', '', 'Công tơ chạy bình thường, chỉ số đúng')) === ''
    && q($pdo, 'SELECT result_conclusion FROM inspection_cases WHERE alert_id = ?', [$aCc['id']]) === 'cong_to_binh_thuong' && alertOf($pdo, 4)['status'] === 'can_kiem_tra_lai' && alertOf($pdo, 4)['conclusion'] === null);

// ---------------------------------------------------------------- Liên hệ cư dân + email
$G = 'CONTACT + EMAIL';
actAs(BQL, 'bql');
$j16wf = new NoUsageAlertWorkflowService();
check($G, 'ghi nhận "Đã gọi chủ hộ" (contact_time, người gọi, kết quả, ghi chú) → alert_contact_logs + audit RECORD_PHONE_CONTACT',
    errorOf(fn () => $j16wf->recordContact((int) $aCc['id'], 'phone', date('Y-m-d\TH:i', time() - 600), 'no_answer', 'Gọi 2 lần không nghe')) === ''
    && (int) q($pdo, "SELECT COUNT(*) FROM alert_contact_logs WHERE alert_id = ? AND contact_type = 'phone' AND result = 'no_answer' AND created_by = ?", [$aCc['id'], BQL]) === 1 && audits($pdo, 'RECORD_PHONE_CONTACT') === 1);
check($G, 'kết quả gọi ngoài danh sách / thời điểm tương lai → lỗi trường', str_contains(errorOf(fn () => $j16wf->recordContact((int) $aCc['id'], 'phone', date('Y-m-d\TH:i'), 'xyz', '')), 'result')
    && str_contains(errorOf(fn () => $j16wf->recordContact((int) $aCc['id'], 'phone', date('Y-m-d\TH:i', time() + 86400), 'contacted', '')), 'contact_time'));
check($G, 'ghi nhận phản hồi cư dân "Đang sửa chữa" → audit RECORD_RESIDENT_RESPONSE',
    errorOf(fn () => $j16wf->recordContact((int) $aCc['id'], 'resident_response', date('Y-m-d\TH:i', time() - 60), 'dang_sua_chua', 'Thợ sửa nhà dùng điện')) === '' && audits($pdo, 'RECORD_RESIDENT_RESPONSE') === 1);

EmailService::$sent = [];
$mail = new EmailNotificationService();
$resident4 = (int) q($pdo, 'SELECT id FROM residents WHERE apartment_id = 4');
$sent = $mail->sendConsumptionVerification((int) $aCc['id'], (string) $resident4);
$n = $pdo->query('SELECT * FROM email_notifications WHERE id = ' . (int) $sent['id'])->fetch();
check($G, 'Case 7: gửi email xác minh → sent, hộp thư đi type consumption_verification + alert_id, không gắn bản tính, transport array (giả)',
    $sent['status'] === 'sent' && $n['notification_type'] === 'consumption_verification' && (int) $n['alert_id'] === (int) $aCc['id'] && $n['apartment_charge_id'] === null && $n['transport'] === 'array' && count(EmailService::$sent) === 1);
check($G, 'tiêu đề "[Energy DSS] Yêu cầu xác minh tiêu thụ điện/nước - Căn CC-2306 - kỳ 07/2024"', $n['subject'] === '[Energy DSS] Yêu cầu xác minh tiêu thụ điện/nước - Căn CC-2306 - kỳ 07/2024', $n['subject']);
check($G, 'nội dung trung tính: khai báo, CL điện 25 kWh / nước 0 m³, "chưa phải kết luận", không từ kết tội, không SĐT / dữ liệu khác',
    str_contains($n['body_text'], "trạng thái 'Không phát sinh điện nước'") && str_contains($n['body_text'], '- Điện: 25 kWh') && str_contains($n['body_text'], '- Nước: 0 m³')
    && str_contains($n['body_text'], 'Đây chưa phải kết luận về nguyên nhân phát sinh tiêu thụ.') && !preg_match('/rò điện|gian lận|trái phép|hỏng công tơ|điện thoại|SĐT/u', $n['body_text'] . $n['body_html'])
    && !str_contains($n['body_html'], 'Tổng phải thu'));
check($G, 'audit SEND_CONSUMPTION_VERIFICATION_EMAIL (email đã che) + lịch sử cảnh báo', audits($pdo, 'SEND_CONSUMPTION_VERIFICATION_EMAIL') === 1
    && !str_contains((string) q($pdo, "SELECT new_value FROM audit_logs WHERE action = 'SEND_CONSUMPTION_VERIFICATION_EMAIL'"), 'le.thi.d@')
    && (int) q($pdo, "SELECT COUNT(*) FROM alert_status_history WHERE alert_id = ? AND note LIKE 'Email xác minh tới%đã gửi'", [$aCc['id']]) === 1);
check($G, 'gửi lại cùng nội dung → chặn trùng (uq_email_alert_dedupe), không gửi thêm', str_contains(errorOf(fn () => $mail->sendConsumptionVerification((int) $aCc['id'], (string) $resident4)), 'Không gửi trùng') && count(EmailService::$sent) === 1);

$resident2 = (int) q($pdo, 'SELECT id FROM residents WHERE apartment_id = 2');
EmailService::fail('SMTP connect() failed (giả lập)');
$failed = $mail->sendConsumptionVerification((int) $aS01['id'], (string) $resident2);
check($G, 'Case 13: lỗi gửi → failed + last_error đã làm sạch; cảnh báo không bị ảnh hưởng (vẫn Mới)', $failed['status'] === 'failed'
    && q($pdo, 'SELECT status FROM email_notifications WHERE id = ?', [$failed['id']]) === 'failed' && alertOf($pdo, 2)['status'] === 'moi' && audits($pdo, 'EMAIL_NOTIFICATION_FAILED') === 1);
EmailService::fail(null);
$retry = $mail->retry((int) $failed['id']);
check($G, 'Case 13: Gửi lại (FN-10.03 retry) → sent, attempt 2', $retry['status'] === 'sent' && (int) q($pdo, 'SELECT attempt_count FROM email_notifications WHERE id = ?', [$failed['id']]) === 2);
$aBt1None = (int) q($pdo, 'SELECT id FROM residents WHERE apartment_id = 1');
check($G, 'Case 14: cư dân không có email → không có người nhận; gửi bị từ chối có thông báo, KHÔNG làm hỏng luồng (vẫn ghi nhận cuộc gọi được)',
    (new App\Repositories\EmailNotificationRepository())->activeRecipients(1, date('Y-m-d')) === []
    && str_contains(errorOf(fn () => $mail->sendConsumptionVerification((int) $aS01['id'], (string) $aBt1None)), 'chưa có email')
    && errorOf(fn () => $j16wf->recordContact((int) $aS01['id'], 'phone', date('Y-m-d\TH:i', time() - 60), 'contacted', '')) === '');

// ---------------------------------------------------------------- Kết luận
$G = 'CONCLUSION';
check($G, 'kết luận thiếu căn cứ → lỗi; D thiếu ngày theo dõi → lỗi', str_contains(errorOf(fn () => $j16wf->conclude((int) $aCc['id'], 'real_consumption_confirmed', ' ', '', token($pdo, (int) $aCc['id']))), 'conclusion_note')
    && str_contains(errorOf(fn () => $j16wf->conclude((int) $aCc['id'], 'undetermined', 'Chưa rõ', '', token($pdo, (int) $aCc['id']))), 'follow_up_date'));
check($G, 'D (Chưa xác định) + ngày theo dõi → giữ trạng thái Cần kiểm tra lại, VẪN chặn Kế toán',
    errorOf(fn () => $j16wf->conclude((int) $aCc['id'], 'undetermined', 'Chờ thêm thông tin', date('Y-m-d', strtotime('+7 days')), token($pdo, (int) $aCc['id']))) === ''
    && alertOf($pdo, 4)['status'] === 'can_kiem_tra_lai' && alertOf($pdo, 4)['follow_up_date'] !== null && (new NoUsageConsumptionService())->blockingAlert(4, JUL) !== null);
$cc = chargeOf($pdo, 4);
check($G, 'D → Kế toán xác nhận vẫn bị chặn', (new BillingConfirmationService())->confirm(JUL, [$cc['id'] => $cc['version_token']])['done'] === []);
check($G, 'C (Tiêu thụ thực tế) → Đã xác nhận, case Đã đóng, audit CONCLUDE_NO_USAGE_ALERT; KHÔNG tự đổi trạng thái căn / chỉ số / phí',
    errorOf(fn () => $j16wf->conclude((int) $aCc['id'], 'real_consumption_confirmed', 'Kỹ thuật xác nhận công tơ đúng, cư dân sửa nhà', '', token($pdo, (int) $aCc['id']))) === ''
    && alertOf($pdo, 4)['status'] === 'da_xac_nhan' && alertOf($pdo, 4)['follow_up_date'] === null && q($pdo, 'SELECT status FROM inspection_cases WHERE alert_id = ?', [$aCc['id']]) === 'da_dong'
    && audits($pdo, 'CONCLUDE_NO_USAGE_ALERT') === 2 && q($pdo, 'SELECT occupancy_status FROM apartments WHERE id = 4') === 'khong_phat_sinh'
    && chargeOf($pdo, 4)['total_due'] === $cc['total_due'] && chargeOf($pdo, 4)['status'] === 'da_tinh');
actAs(KTO, 'ke_toan');
$cc = chargeOf($pdo, 4);
$res = (new BillingConfirmationService())->confirm(JUL, [$cc['id'] => $cc['version_token']]);
check($G, 'Case 12: sau kết luận C → Kế toán xác nhận được bản tính CC-2306', $res['done'] === [(int) $cc['id']], json_encode($res, JSON_UNESCAPED_UNICODE));
actAs(BQL, 'bql');
check($G, 'đóng cảnh báo (đã kết luận C) → Đã đóng + audit CLOSE_NO_USAGE_ALERT', errorOf(fn () => $wf->close((int) $aCc['id'], 'Hoàn tất xác minh', token($pdo, (int) $aCc['id']))) === ''
    && alertOf($pdo, 4)['status'] === 'da_dong' && audits($pdo, 'CLOSE_NO_USAGE_ALERT') === 1);
check($G, 'cảnh báo đã đóng: đánh giá lại không mở lại / không tạo mới', ((new NoUsageConsumptionService())->evaluatePeriod(JUL, 'test')['created'] === []) && alertOf($pdo, 4)['status'] === 'da_dong'
    && (int) q($pdo, 'SELECT COUNT(*) FROM alerts WHERE alert_type = ? AND apartment_id = 4', [TYPE]) === 1);

// B - chỉ số ghi sai (S01)
$G = 'CONCLUSION B';
$wf->review((int) $aS01['id'], 'thap', '', token($pdo, (int) $aS01['id']));
check($G, 'đóng cảnh báo J-16 (Đã review) khi chưa kết luận A/B/C → chặn', str_contains(errorOf(fn () => $wf->close((int) $aS01['id'], 'x', token($pdo, (int) $aS01['id']))), 'kết luận A, B hoặc C')
    && alertOf($pdo, 2)['status'] === 'da_review');
check($G, 'điều chỉnh chỉ số khi chưa kết luận B → chặn', str_contains(errorOf(fn () => $j16wf->adjustReading((int) $aS01['id'], '0', '130', 'x', '', token($pdo, (int) $aS01['id']))), 'B. Chỉ số'));
check($G, 'kết luận B (chỉ số ghi sai) → Đã xác nhận, gỡ chặn', errorOf(fn () => $j16wf->conclude((int) $aS01['id'], 'reading_incorrect', 'Đọc nhầm 130,5 thay vì 130', '', token($pdo, (int) $aS01['id']))) === ''
    && (new NoUsageConsumptionService())->blockingAlert(2, JUL) === null);
$snapS = json_decode((string) alertOf($pdo, 2)['snapshot_json'], true);
$waterReading = (int) array_values(array_filter($snapS['meters'], fn ($m) => $m['utility_type'] === 'nuoc'))[0]['reading_id'];
$rowW = $pdo->query('SELECT * FROM meter_readings WHERE id = ' . $waterReading)->fetch();
check($G, 'chỉ số ngoài cảnh báo → từ chối', str_contains(errorOf(fn () => $j16wf->adjustReading((int) $aS01['id'], '1', '1500', 'x', 'x', token($pdo, (int) $aS01['id']))), 'reading_id'));
check($G, 'Case 10: điều chỉnh CSC S01-NUOC 130,5 → 130 qua FN-04.07: CL tính lại 0, về Nháp, audit ADJUST_READING_FROM_ALERT + UPDATE_METER_READING',
    errorOf(fn () => $j16wf->adjustReading((int) $aS01['id'], (string) $waterReading, '130', 'Đọc lại tại chỗ', MeterReadingService::versionToken($rowW), token($pdo, (int) $aS01['id']))) === ''
    && q($pdo, 'SELECT consumption FROM meter_readings WHERE id = ?', [$waterReading]) === '0.00' && q($pdo, 'SELECT status FROM meter_readings WHERE id = ?', [$waterReading]) === 'nhap'
    && audits($pdo, 'ADJUST_READING_FROM_ALERT') === 1);
$s01Charge = chargeOf($pdo, 2);
check($G, 'bản tính S01 đã có → CẦN TÍNH LẠI (FN-05 tự đánh dấu)', (new BillingCalculationService())->consistency($pdo->query('SELECT * FROM billing_periods WHERE id = ' . JUL)->fetch(), $s01Charge)['state'] !== 'current');
$row = $pdo->query('SELECT * FROM meter_readings WHERE id = ' . $waterReading)->fetch();
$again = (new ReadingApprovalService())->approve(JUL, [$waterReading => MeterReadingService::versionToken($row)]);
$snapS = json_decode((string) alertOf($pdo, 2)['snapshot_json'], true);
check($G, 'duyệt lại → J-16 đánh giá lại: CL về 0 → ghi nhận "chờ BQL xác nhận đóng", KHÔNG tự đóng / xoá, lịch sử giữ', in_array((int) $aS01['id'], $again['j16']['noted'] ?? [], true)
    && ($snapS['data_no_longer_matches'] ?? false) === true && alertOf($pdo, 2)['status'] === 'da_xac_nhan'
    && (int) q($pdo, "SELECT COUNT(*) FROM alert_status_history WHERE alert_id = ? AND note LIKE '%chờ BQL xác nhận đóng%'", [$aS01['id']]) === 1);

// A - trạng thái căn khai báo sai (BT1 đổi khai báo -> cảnh báo Điện + Nước)
$G = 'CONCLUSION A';
$pdo->exec("UPDATE apartments SET occupancy_status = 'khong_phat_sinh' WHERE id = 1");
$r = (new NoUsageConsumptionService())->evaluatePeriod(JUL, 'test');
$aBt1 = alertOf($pdo, 1);
check($G, 'BT1 khai báo không phát sinh + CL điện 343 & nước 16 → cảnh báo utility_type NULL ("Điện + Nước"), gợi ý Cao (≥ 100 kWh)', $aBt1 !== null && $aBt1['utility_type'] === null
    && $aBt1['actual_value'] === null && $aBt1['priority'] === 'cao' && NoUsageConsumptionService::utilityLabel($aBt1) === 'Điện + Nước');
$wf->review((int) $aBt1['id'], 'cao', '', token($pdo, (int) $aBt1['id']));
check($G, 'cập nhật trạng thái căn khi chưa kết luận A → chặn', str_contains(errorOf(fn () => $j16wf->updateApartmentStatus((int) $aBt1['id'], '1', token($pdo, (int) $aBt1['id']))), 'A. Trạng thái'));
$j16wf->conclude((int) $aBt1['id'], 'apartment_status_incorrect', 'Cư dân xác nhận đang ở', '', token($pdo, (int) $aBt1['id']));
check($G, 'Case 9: kết luận A → CTA cần xác nhận rõ (thiếu tích → lỗi), KHÔNG tự đổi', str_contains(errorOf(fn () => $j16wf->updateApartmentStatus((int) $aBt1['id'], '', token($pdo, (int) $aBt1['id']))), 'confirm_apartment')
    && q($pdo, 'SELECT occupancy_status FROM apartments WHERE id = 1') === 'khong_phat_sinh');
check($G, 'Case 9: BQL xác nhận → căn đổi khai báo "Có ở", audit UPDATE_APARTMENT_FROM_ALERT, lịch sử cảnh báo',
    errorOf(fn () => $j16wf->updateApartmentStatus((int) $aBt1['id'], '1', token($pdo, (int) $aBt1['id']))) === '' && q($pdo, 'SELECT occupancy_status FROM apartments WHERE id = 1') === 'co_o'
    && audits($pdo, 'UPDATE_APARTMENT_FROM_ALERT') === 1 && (int) q($pdo, "SELECT COUNT(*) FROM alert_status_history WHERE alert_id = ? AND note LIKE 'Cập nhật trạng thái căn%'", [$aBt1['id']]) === 1);
check($G, 'cập nhật lần 2 → báo căn không còn khai báo "Không phát sinh"', str_contains(errorOf(fn () => $j16wf->updateApartmentStatus((int) $aBt1['id'], '1', token($pdo, (int) $aBt1['id']))), 'không còn khai báo'));

// ---------------------------------------------------------------- Không tự động làm thay con người
$G = 'NO AUTO ACTION';
check($G, 'không có cảnh báo J-16 nào tự đóng; căn CC-2306 / S01 / CC-2305 giữ khai báo; không bản tính nào tự xác nhận',
    (int) q($pdo, "SELECT COUNT(*) FROM alerts WHERE alert_type = ? AND status = 'da_dong' AND closed_by IS NULL", [TYPE]) === 0
    && q($pdo, "SELECT GROUP_CONCAT(occupancy_status ORDER BY id) FROM apartments WHERE id IN (2, 3, 4)") === 'khong_phat_sinh,khong_phat_sinh,khong_phat_sinh'
    && (int) q($pdo, "SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ? AND status = 'da_xac_nhan' AND confirmed_by IS NULL", [JUL]) === 0);
check($G, 'Gmail thật không được gọi: mọi email transport = array', (int) q($pdo, "SELECT COUNT(*) FROM email_notifications WHERE transport <> 'array'") === 0);

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
echo "\n{$pass}/{$total} PASS (J-16 service test, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
