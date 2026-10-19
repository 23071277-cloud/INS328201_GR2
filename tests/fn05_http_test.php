<?php

declare(strict_types=1);

/**
 * STEP 9 - Test HTTP FN-05 qua Apache XAMPP THẬT (APP_URL) trên DB của .env (energy_dss).
 *   php tests/fn05_http_test.php
 * Luồng: BQL tạo kỳ TEST riêng (e2e_pick_period: năm 2096 hoặc tháng trống không bị chặn CSĐ) -> Kỹ thuật nhập chỉ số -> BQL duyệt -> Kế toán tính phí, xem breakdown,
 * xác nhận -> Kế toán khoá kỳ -> chặn sửa -> BQL mở lại (lý do) -> sửa chỉ số -> CẦN TÍNH LẠI ->
 * tính lại, xác nhận lại, khoá lại. RBAC / CSRF / input sai kiểu / audit / app.log.
 * XOÁ toàn bộ dữ liệu E2E ở cuối (audit_logs giữ lại - BR-04).
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/real_db_fixture.php'; // fixture riêng: không phụ thuộc / không sửa dữ liệu thật
restore_error_handler();
restore_exception_handler();

use App\Core\Database;
use App\Services\MeterReadingService;

$base = rtrim((string) config('app.url'), '/');
require_once __DIR__ . '/support/legacy_compat.php'; // STEP 4C: chạy nguyên văn trên schema v1 hoặc v2
$pdo = legacy_compat_install();
$runId = date('His') . random_int(10, 99);
$jar = static fn (string $who): string => sys_get_temp_dir() . "/fn05_{$who}_{$runId}.txt";
$logFile = BASE_PATH . '/storage/logs/app.log';
clearstatcache();
$logBefore = filesize($logFile);
$snapshotCounts = static fn (): array => [
    'tariffs' => (int) $pdo->query('SELECT COUNT(*) FROM tariffs')->fetchColumn(),
    'fees' => (int) $pdo->query('SELECT COUNT(*) FROM fixed_fee_rates')->fetchColumn(),
    // căn của người dùng (bỏ tòa fixture E2E-* tạo / xoá trong lượt chạy)
    'apartments' => $pdo->query("SELECT GROUP_CONCAT(CONCAT(a.id, ':', a.area, ':', a.occupancy_status) ORDER BY a.id) FROM apartments a JOIN buildings b ON b.id = a.building_id WHERE b.code NOT LIKE 'E2E-%'")->fetchColumn(),
];
$masterBefore = $snapshotCounts();

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

/** token_<id> trong form duyệt/xác nhận -> [id => token] */
function tokensFrom(string $html): array
{
    preg_match_all('/name="token_(\d+)" value="([0-9a-f]{40})"/', $html, $m, PREG_SET_ORDER);

    return array_column(array_map(static fn ($x) => [(int) $x[1], $x[2]], $m), 1, 0);
}

/** Lấy POST body cho danh sách id + token (ids[]=..&token_id=..) */
function bulkBody(string $csrf, string $listName, array $tokens): string
{
    $parts = ['csrf_token=' . urlencode($csrf)];
    foreach ($tokens as $id => $token) {
        $parts[] = urlencode($listName . '[]') . '=' . $id;
        $parts[] = 'token_' . $id . '=' . $token;
    }

    return implode('&', $parts);
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

function charge(PDO $pdo, int $periodId, int $apartmentId): array
{
    $s = $pdo->prepare('SELECT * FROM apartment_charges WHERE billing_period_id = ? AND apartment_id = ?');
    $s->execute([$periodId, $apartmentId]);

    return $s->fetch() ?: [];
}

$periodId = 0;
$fx = e2e_fixture_create($pdo, $runId);
$tp = e2e_pick_period($pdo, 2096); // kỳ test riêng, không trùng / không bị chặn bởi kỳ người dùng
$month = $tp['month'];
$year = $tp['year'];
$periodCode = $tp['code'];
$M = $fx['meter'];
$A = $fx['apt'];
try {
    foreach (['bql' => 'bql01', 'kt' => 'kythuat01', 'kto' => 'ketoan01'] as $who => $user) {
        check('LOGIN', "Đăng nhập {$user}", login($base, $jar($who), $user));
    }

    // ================================================================ BQL tạo kỳ
    $form = http($jar('bql'), 'GET', $base . '/periods/create');
    $r = http($jar('bql'), 'POST', $base . '/periods', ['csrf_token' => csrfFrom($form['body']), 'month' => (string) $month, 'year' => (string) $year]);
    $periodId = preg_match('#/periods/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
    $fx['periods'][] = $periodId;
    check('SETUP', "BQL tạo kỳ {$periodCode}", $periodId > 0);

    // ================================================================ Kỹ thuật nhập chỉ số 8 công tơ FIXTURE qua web (CSĐ = chỉ số khởi tạo = CSC 06/2024 seed)
    // CC-2306 khai báo "không phát sinh" giữ CL = 0 (CL > 0 -> cảnh báo J-16 chặn xác nhận / khoá kỳ - test riêng run_j16_tests.sh)
    $deltas = [1 => '343', 2 => '16', 3 => '10', 4 => '10', 5 => '10', 6 => '10', 7 => '0', 8 => '0'];
    $entered = 0;
    foreach ($deltas as $seedId => $delta) {
        $csc = bcadd(E2E_SEED_METERS[$seedId][3], $delta, 2);
        $page = http($jar('kt'), 'GET', $base . "/periods/{$periodId}/readings/create?meter_id={$M[$seedId]}");
        $r = http($jar('kt'), 'POST', $base . "/periods/{$periodId}/readings", ['csrf_token' => csrfFrom($page['body']), 'meter_id' => (string) $M[$seedId], 'current_reading' => $csc, 'note' => "E2E {$runId}"]);
        $entered += $r['code'] === 302 ? 1 : 0;
    }
    // công tơ thật khác: CL = 0 chỉ trong kỳ test (khoá kỳ cần mọi công tơ có chỉ số); bị chặn B7 -> phụ thuộc dữ liệu thật
    $others = e2e_fill_other_meters($pdo, $fx, $periodId);
    $realBlocked = $others['blocked'];
    $readingCount = (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ?', [$periodId]);
    check('READING', 'Kỹ thuật nhập 8 chỉ số fixture qua web (Nháp)', $entered === 8 && $readingCount === 8 + $others['created']
        && (int) q($pdo, "SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ? AND status = 'nhap'", [$periodId]) === $readingCount);
    $bt1Reading = (int) q($pdo, 'SELECT id FROM meter_readings WHERE billing_period_id = ? AND meter_id = ?', [$periodId, $M[1]]);
    check('READING', 'BT1 điện: CL = 343 kWh do server tính', q($pdo, 'SELECT consumption FROM meter_readings WHERE id = ?', [$bt1Reading]) === '343.00');

    // Kế toán tính khi chỉ số còn Nháp -> từng căn báo lỗi BR-11, không tạo bản tính
    $billing = http($jar('kto'), 'GET', $base . "/periods/{$periodId}/billing");
    check('BILLING', 'GET /periods/{id}/billing (Kế toán) 200 + sidebar Kế toán nhóm "Tính phí & chốt kỳ" mở sẵn (trang đang mở)', $billing['code'] === 200 && (bool) preg_match('#<div class="nav-group is-open" data-nav-group>\s*<button[^>]*aria-expanded="true"[^>]*>\s*<span class="nav-group__label">Tính phí &amp; chốt kỳ</span>#u', $billing['body']));
    $r = http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/calculate", ['csrf_token' => csrfFrom($billing['body'])]);
    $after = http($jar('kto'), 'GET', $base . "/periods/{$periodId}/billing")['body'];
    check('BILLING', 'Tính phí khi chỉ số còn Nháp -> không tạo bản tính, báo BR-11', $r['code'] === 302 && (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ?', [$periodId]) === 0 && str_contains($after, 'BR-11'));

    // ================================================================ BQL duyệt
    $ap = http($jar('bql'), 'GET', $base . "/periods/{$periodId}/approvals");
    $tokens = tokensFrom($ap['body']);
    $r = http($jar('bql'), 'POST', $base . "/periods/{$periodId}/approvals", bulkBody(csrfFrom($ap['body']), 'reading_ids', $tokens));
    check('READING', 'BQL duyệt theo lô -> mọi chỉ số Đã xác nhận', $r['code'] === 302 && count($tokens) === $readingCount
        && (int) q($pdo, "SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ? AND status = 'da_xac_nhan'", [$periodId]) === $readingCount);

    // ================================================================ Kế toán tính phí (giá trị tiền gửi giả bị bỏ qua)
    $billing = http($jar('kto'), 'GET', $base . "/periods/{$periodId}/billing");
    $r = http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/calculate", ['csrf_token' => csrfFrom($billing['body']), 'electricity_amount' => '1', 'consumption' => '1', 'total_due' => '1', 'unit_price' => '1']);
    $bt1 = charge($pdo, $periodId, $A['BT1']);
    $charges = (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ?', [$periodId]);
    check('BILLING', "Kế toán tính phí cả kỳ qua web -> {$charges} bản tính Đã tính", $r['code'] === 302 && $charges >= 4 && (int) q($pdo, "SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ? AND status <> 'da_tinh'", [$periodId]) === 0);
    check('BILLING', 'REGRESSION 343 kWh qua web: 804.350 + VAT 64.348 = 868.698 (tiền gửi từ form bị bỏ qua)', ($bt1['electricity_amount'] ?? '') === '804350.00' && $bt1['electricity_vat_amount'] === '64348.00');
    check('BILLING', 'REGRESSION nước 16 m³ = 185.120', ($bt1['water_amount'] ?? '') === '185120.00');
    check('BILLING', 'Tổng = breakdown; tổng phải thu = phát sinh + nợ cũ (mọi bản tính)', (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ? AND (total_arising <> electricity_amount + electricity_vat_amount + water_amount + service_fee_amount + fire_insurance_amount + parking_fee_amount OR total_due <> total_arising + old_debt_amount)', [$periodId]) === 0);
    $r2 = http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/calculate", ['csrf_token' => csrfFrom($billing['body'])]);
    check('BILLING', 'Bấm Tính phí lần 2 -> không sinh bản tính trùng', (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ?', [$periodId]) === $charges
        && str_contains(http($jar('kto'), 'GET', $base . "/periods/{$periodId}/billing")['body'], 'không đổi'));

    $detail = http($jar('kto'), 'GET', $base . '/charges/' . $bt1['id']);
    $b = $detail['body'];
    check('DETAIL', 'Chi tiết bản tính BT1: từng bậc (90.300 … 131.150), TT 804.350, VAT 8% 64.348, thành tiền 868.698',
        $detail['code'] === 200 && str_contains($b, '90.300 đ') && str_contains($b, '131.150 đ') && str_contains($b, '804.350 đ') && str_contains($b, '64.348 đ') && str_contains($b, '868.698 đ'));
    check('DETAIL', 'Nước: 110.450 + 74.670 = 185.120, "không tách VAT" (J-07)', str_contains($b, '110.450 đ') && str_contains($b, '74.670 đ') && str_contains($b, '185.120 đ') && str_contains($b, 'J-07'));
    check('DETAIL', 'Phí cố định: cơ sở tính (m², xe), đơn giá, phiên bản; phí mở lại NOT CALCULATED (J-09)', str_contains($b, 'm²') && str_contains($b, 'Gửi ô tô') && str_contains($b, 'NOT CALCULATED') && str_contains($b, 'J-09'));
    check('DETAIL', 'Công tơ -> chỉ số -> biểu giá: link chỉ số + biểu giá, tổng phải thu, nợ cũ NEED BA', str_contains($b, '/readings/' . $bt1Reading) && str_contains($b, '/tariffs/') && str_contains($b, 'Tổng phải thu') && str_contains($b, 'NEED BA CONFIRMATION J-09'));

    // ================================================================ RBAC / CSRF / input sai kiểu
    check('RBAC', 'Kỹ thuật GET billing -> 403', http($jar('kt'), 'GET', $base . "/periods/{$periodId}/billing")['code'] === 403);
    check('RBAC', 'Kỹ thuật GET chi tiết bản tính (URL trực tiếp) -> 403', http($jar('kt'), 'GET', $base . '/charges/' . $bt1['id'])['code'] === 403);
    check('RBAC', 'Kỹ thuật POST tính phí -> 403', http($jar('kt'), 'POST', $base . "/periods/{$periodId}/billing/calculate", ['csrf_token' => csrfFrom($billing['body'])])['code'] === 403);
    check('RBAC', 'BQL POST tính phí -> 403 (J-14: Kế toán)', http($jar('bql'), 'POST', $base . "/periods/{$periodId}/billing/calculate", ['csrf_token' => 'x'])['code'] === 403);
    check('RBAC', 'BQL POST xác nhận -> 403', http($jar('bql'), 'POST', $base . "/periods/{$periodId}/billing/confirm", ['csrf_token' => 'x'])['code'] === 403);
    check('RBAC', 'BQL POST khóa kỳ -> 403 (ASSUMPTION J-10: Kế toán)', http($jar('bql'), 'POST', $base . "/periods/{$periodId}/lock", ['csrf_token' => 'x'])['code'] === 403);
    check('RBAC', 'Kế toán POST mở lại kỳ -> 403 (chỉ BQL)', http($jar('kto'), 'POST', $base . "/periods/{$periodId}/reopen", ['csrf_token' => 'x', 'reopen_reason' => 'x'])['code'] === 403);
    check('RBAC', 'Chỉ 3 vai trò: admin01 không đăng nhập được (J-01 đã chốt)', !login($base, $jar('adm'), 'admin01'));
    check('RBAC', 'Kế toán không sửa được biểu giá ở đây (POST /tariffs -> 403)', http($jar('kto'), 'POST', $base . '/tariffs', ['csrf_token' => 'x'])['code'] === 403);
    $kp = http($jar('kt'), 'GET', $base . "/periods/{$periodId}")['body'];
    check('RBAC', 'Trang kỳ của Kỹ thuật: không có link Tính phí / số tiền', !str_contains($kp, '/billing') && !str_contains($kp, '804.350'));
    $r = http($jar('kto'), 'POST', $base . '/charges/' . $bt1['id'] . '/return', ['csrf_token' => 'invalid', 'rejection_reason' => 'x', 'version_token' => 'x']);
    check('CSRF', 'POST trả lại với CSRF sai -> 403, không đổi trạng thái', $r['code'] === 403 && charge($pdo, $periodId, $A['BT1'])['status'] === 'da_tinh');
    $r = http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/calculate", 'csrf_token=' . urlencode(csrfFrom($billing['body'])) . '&apartment_id[]=1');
    check('ROBUST', 'apartment_id[] (mảng) -> không 500', $r['code'] === 302);
    $r = http($jar('kto'), 'POST', $base . '/charges/' . $bt1['id'] . '/return', 'csrf_token=' . urlencode(csrfFrom($billing['body'])) . '&rejection_reason[]=x&version_token[]=y');
    check('ROBUST', 'rejection_reason[] (mảng) -> lỗi validation, không 500', $r['code'] === 302 && charge($pdo, $periodId, $A['BT1'])['status'] === 'da_tinh');
    $r = http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/confirm", 'csrf_token=' . urlencode(csrfFrom($billing['body'])) . '&charge_ids=abc&back[]=charge');
    check('ROBUST', 'charge_ids sai kiểu -> báo chọn bản tính, không 500', $r['code'] === 302);

    // ================================================================ Kế toán xác nhận (Review list)
    $review = http($jar('kto'), 'GET', $base . "/periods/{$periodId}/billing/review");
    $tokens = tokensFrom($review['body']);
    $r = http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/confirm", bulkBody(csrfFrom($review['body']), 'charge_ids', $tokens));
    check('CONFIRM', 'Review list -> xác nhận theo lô -> mọi bản tính Đã xác nhận', $review['code'] === 200 && count($tokens) === $charges
        && (int) q($pdo, "SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ? AND status = 'da_xac_nhan'", [$periodId]) === $charges);
    $alloc = http($jar('bql'), 'GET', $base . "/periods/{$periodId}/cost-allocation");
    check('ALLOCATION', 'BQL xem tổng hợp chi phí theo tòa/khu/căn (TB/căn)', $alloc['code'] === 200 && str_contains($alloc['body'], 'Theo khu vực') && str_contains($alloc['body'], 'TB/căn'));

    // ================================================================ Kế toán khoá kỳ
    $pp = http($jar('kto'), 'GET', $base . "/periods/{$periodId}");
    if ($realBlocked !== []) {
        check('LOCK', 'PHỤ THUỘC DỮ LIỆU THẬT (không phải lỗi code): không khoá được kỳ test vì ' . count($realBlocked) . ' công tơ thật bị chặn CSĐ — bỏ qua khoá / mở lại (đã kiểm trên DB tạm)',
            false, json_encode($realBlocked, JSON_UNESCAPED_UNICODE));
        throw new RuntimeException('skip-lock');
    }
    check('LOCK', 'Trang kỳ (Kế toán): "Đủ điều kiện khóa" + nút Khóa kỳ (modal)', str_contains($pp['body'], 'Đủ điều kiện khóa kỳ') && str_contains($pp['body'], 'lockModal'));
    $r = http($jar('kto'), 'POST', $base . "/periods/{$periodId}/lock", ['csrf_token' => csrfFrom($pp['body'])]);
    check('LOCK', 'Khóa kỳ -> Đã khóa, locked_by = Kế toán', $r['code'] === 302 && q($pdo, 'SELECT status FROM billing_periods WHERE id = ?', [$periodId]) === 'da_khoa');
    $r = http($jar('kto'), 'POST', $base . "/periods/{$periodId}/lock", ['csrf_token' => csrfFrom($pp['body'])]);
    check('LOCK', 'Khóa lần 2 -> thông báo đã khóa, không lỗi', $r['code'] === 302 && str_contains(http($jar('kto'), 'GET', $base . "/periods/{$periodId}")['body'], 'đã được khóa trước đó'));
    $edit = http($jar('kt'), 'GET', $base . "/readings/{$bt1Reading}/edit");
    preg_match('/name="version_token" value="([^"]+)"/', $edit['body'], $vt);
    $r = http($jar('kt'), 'POST', $base . "/readings/{$bt1Reading}/update", ['csrf_token' => csrfFrom($pp['body']), 'current_reading' => '1700', 'note' => 'sau khóa', 'version_token' => $vt[1] ?? '']);
    check('LOCK', 'Sau khóa: sửa chỉ số qua URL trực tiếp bị chặn', q($pdo, 'SELECT current_reading FROM meter_readings WHERE id = ?', [$bt1Reading]) === '1686.00');
    $before = charge($pdo, $periodId, $A['BT1'])['updated_at'];
    http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/calculate", ['csrf_token' => csrfFrom($pp['body']), 'apartment_id' => (string) $A['BT1']]);
    check('LOCK', 'Sau khóa: tính lại phí qua URL trực tiếp bị chặn', charge($pdo, $periodId, $A['BT1'])['updated_at'] === $before && charge($pdo, $periodId, $A['BT1'])['status'] === 'da_xac_nhan');

    // ================================================================ BQL mở lại
    $bp = http($jar('bql'), 'GET', $base . "/periods/{$periodId}");
    check('REOPEN', 'BQL thấy nút Mở lại kỳ (modal + lý do)', str_contains($bp['body'], 'reopenModal'));
    $r = http($jar('bql'), 'POST', $base . "/periods/{$periodId}/reopen", ['csrf_token' => csrfFrom($bp['body']), 'reopen_reason' => '  ']);
    $bp = http($jar('bql'), 'GET', $base . "/periods/{$periodId}");
    check('REOPEN', 'Thiếu lý do -> lỗi trong modal, kỳ vẫn Đã khóa', str_contains($bp['body'], 'Vui lòng nhập lý do mở lại kỳ.') && q($pdo, 'SELECT status FROM billing_periods WHERE id = ?', [$periodId]) === 'da_khoa');
    $r = http($jar('bql'), 'POST', $base . "/periods/{$periodId}/reopen", ['csrf_token' => csrfFrom($bp['body']), 'reopen_reason' => "E2E {$runId}: cư dân khiếu nại <b>chỉ số</b>"]);
    $bp = http($jar('bql'), 'GET', $base . "/periods/{$periodId}")['body'];
    check('REOPEN', 'Mở lại -> Mở lại, lý do lưu + hiển thị đã escape', q($pdo, 'SELECT status FROM billing_periods WHERE id = ?', [$periodId]) === 'mo_lai'
        && str_contains($bp, '&lt;b&gt;chỉ số&lt;/b&gt;') && !str_contains($bp, '<b>chỉ số</b>'));
    check('REOPEN', 'Bản tính cũ giữ nguyên sau mở lại', (int) q($pdo, "SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ? AND status = 'da_xac_nhan'", [$periodId]) === $charges);

    // Sau mở lại: sửa chỉ số -> CẦN TÍNH LẠI -> khoá bị chặn -> duyệt, tính lại, xác nhận, khoá lại
    $edit = http($jar('kt'), 'GET', $base . "/readings/{$bt1Reading}/edit");
    preg_match('/name="version_token" value="([^"]+)"/', $edit['body'], $vt);
    $r = http($jar('kt'), 'POST', $base . "/readings/{$bt1Reading}/update", ['csrf_token' => csrfFrom($edit['body']), 'current_reading' => '1696', 'note' => 'Khiếu nại: đọc lại', 'version_token' => $vt[1] ?? '']);
    check('AFTER_REOPEN', 'Kỹ thuật sửa chỉ số BT1 (353 kWh) -> Nháp', q($pdo, 'SELECT CONCAT(consumption, status) FROM meter_readings WHERE id = ?', [$bt1Reading]) === '353.00nhap');
    $bl = http($jar('kto'), 'GET', $base . "/periods/{$periodId}/billing")['body'];
    check('AFTER_REOPEN', 'Danh sách tính phí: BT1 "CẦN TÍNH LẠI"', str_contains($bl, 'CẦN TÍNH LẠI'));
    $pp = http($jar('kto'), 'GET', $base . "/periods/{$periodId}");
    http($jar('kto'), 'POST', $base . "/periods/{$periodId}/lock", ['csrf_token' => csrfFrom($pp['body'])]);
    $msg = http($jar('kto'), 'GET', $base . "/periods/{$periodId}")['body'];
    check('AFTER_REOPEN', 'Khóa lại bị chặn kèm lý do (Nháp / CẦN TÍNH LẠI)', q($pdo, 'SELECT status FROM billing_periods WHERE id = ?', [$periodId]) === 'mo_lai' && str_contains($msg, 'Chưa khóa được kỳ') && str_contains($msg, 'Nháp'));
    $ap = http($jar('bql'), 'GET', $base . "/periods/{$periodId}/approvals");
    http($jar('bql'), 'POST', $base . "/periods/{$periodId}/approvals", bulkBody(csrfFrom($ap['body']), 'reading_ids', tokensFrom($ap['body'])));
    $cd = http($jar('kto'), 'GET', $base . '/charges/' . $bt1['id']);
    check('AFTER_REOPEN', 'Chi tiết BT1: CẦN TÍNH LẠI + nút Tính lại; không có nút Xác nhận', str_contains($cd['body'], 'CẦN TÍNH LẠI') && str_contains($cd['body'], '>Tính lại</button>') && !str_contains($cd['body'], '/billing/confirm"'));
    $r = http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/calculate", ['csrf_token' => csrfFrom($cd['body']), 'apartment_id' => (string) $A['BT1'], 'back' => 'charge']);
    $bt1 = charge($pdo, $periodId, $A['BT1']);
    check('AFTER_REOPEN', 'Tính lại BT1 -> 353 kWh, về Đã tính, cùng id', $r['location'] !== '' && str_contains($r['location'], '/charges/' . $bt1['id']) && $bt1['electricity_consumption'] === '353.00' && $bt1['status'] === 'da_tinh');
    $cd = http($jar('kto'), 'GET', $base . '/charges/' . $bt1['id']);
    preg_match('/name="token_' . $bt1['id'] . '" value="([0-9a-f]{40})"/', $cd['body'], $tk);
    $r = http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/confirm", bulkBody(csrfFrom($cd['body']), 'charge_ids', [(int) $bt1['id'] => $tk[1] ?? '']) . '&back=charge');
    check('AFTER_REOPEN', 'Xác nhận lại từ trang chi tiết', charge($pdo, $periodId, $A['BT1'])['status'] === 'da_xac_nhan' && str_contains($r['location'], '/charges/' . $bt1['id']));
    $pp = http($jar('kto'), 'GET', $base . "/periods/{$periodId}");
    http($jar('kto'), 'POST', $base . "/periods/{$periodId}/lock", ['csrf_token' => csrfFrom($pp['body'])]);
    check('AFTER_REOPEN', 'Khóa lại -> Đã khóa', q($pdo, 'SELECT status FROM billing_periods WHERE id = ?', [$periodId]) === 'da_khoa');

    // ================================================================ Audit + log
    $s = $pdo->prepare("SELECT action, COUNT(*) FROM audit_logs WHERE (entity_type = 'billing_period' AND entity_id = :p)
        OR (entity_type = 'apartment_charge' AND entity_id IN (SELECT CAST(id AS CHAR) FROM apartment_charges WHERE billing_period_id = :p2)) GROUP BY action");
    $s->execute(['p' => (string) $periodId, 'p2' => $periodId]);
    $seen = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    check('AUDIT', 'Audit: CALCULATE / RECALCULATE / CONFIRM_APARTMENT_CHARGE, CALCULATE_BILLING_PERIOD, LOCK ×2, REOPEN ×1',
        ($seen['CALCULATE_APARTMENT_CHARGE'] ?? 0) == $charges && ($seen['RECALCULATE_APARTMENT_CHARGE'] ?? 0) >= 1 && ($seen['CONFIRM_APARTMENT_CHARGE'] ?? 0) == $charges + 1
        && ($seen['LOCK_BILLING_PERIOD'] ?? 0) == 2 && ($seen['REOPEN_BILLING_PERIOD'] ?? 0) == 1 && ($seen['CALCULATE_BILLING_PERIOD'] ?? 0) >= 2, json_encode($seen));
    check('MASTER', 'Biểu giá / phí / căn hộ của DB thật không bị E2E thay đổi', $snapshotCounts() === $masterBefore);
    clearstatcache();
    $newLog = (string) file_get_contents($logFile, false, null, $logBefore);
    check('LOG', 'app.log: không có lỗi PHP / Uncaught (CSRF sai ghi [SECURITY])', !preg_match('/PHP Error|Fatal Error|Uncaught|Deadlock/i', $newLog) && str_contains($newLog, '[SECURITY] CSRF token rejected'));
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'skip-lock') {
        throw $e;
    }
} finally {
    e2e_cleanup($pdo, $fx); // chỉ kỳ test + fixture
    $left = e2e_leftovers($pdo, $fx); // theo id kỳ test + id fixture - không đếm kỳ / dữ liệu người dùng
    check('CLEANUP', 'Đã xóa dữ liệu E2E (kỳ test + fixture, chỉ số, bản tính) khỏi DB thật, audit giữ lại', $left === 0, "còn {$left}");
    foreach (['bql', 'kt', 'kto', 'adm'] as $who) {
        @unlink($jar($who));
    }
}

$failed = 0;
foreach ($results as [$g, $n, $ok, $d]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-12s %s%s\n", $ok ? 'PASS' : 'FAIL', $g, $n, $ok || $d === '' ? '' : "  -> {$d}");
}
printf("\n%d/%d PASS (HTTP test qua %s, run %s)\n", count($results) - $failed, count($results), $base, $runId);
exit($failed === 0 ? 0 : 1);
