<?php

declare(strict_types=1);

/**
 * STEP 14 - Kịch bản E2E DUY NHẤT xuyên Core MVP qua Apache XAMPP THẬT (APP_URL), DB energy_dss, kỳ TEST riêng (e2e_pick_period: năm 2092,
 *   hoặc tháng trống trước kỳ người dùng còn chỉ số Nháp - không phụ thuộc kỳ / chỉ số thật).
 *   php tests/core_e2e_test.php
 * BQL tạo kỳ → KT nhập 8 chỉ số → BQL duyệt → KTo tính phí + xác nhận → BQL baseline / mục tiêu (FN-06) → BQL rule + đánh giá
 * (FN-07) → review → yêu cầu kiểm tra → KT kết quả → BQL xác nhận → sinh khuyến nghị (FN-08) → đóng cảnh báo →
 * dashboard / báo cáo / phiếu / CSV (FN-09) → KTo khoá kỳ → mọi thao tác thay đổi bị chặn. Mỗi bước đúng vai, đúng quyền.
 * XOÁ toàn bộ dữ liệu E2E ở cuối (audit giữ lại - BR-04).
 * Cách ly dữ liệu thật: mọi số liệu kiểm trên tòa / căn / công tơ FIXTURE riêng (tests/support/real_db_fixture.php, mô phỏng seed);
 * công tơ thật khác chỉ nhận chỉ số CL = 0 TRONG KỲ TEST (để khoá kỳ được) và bị xoá cùng kỳ. Không đếm / không xoá dữ liệu người dùng.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/real_db_fixture.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Database;


$base = rtrim((string) config('app.url'), '/');
$pdo = Database::connection();
$runId = date('His') . random_int(10, 99);
$jar = static fn (string $who): string => sys_get_temp_dir() . "/e2e_{$who}_{$runId}.txt";
$logFile = BASE_PATH . '/storage/logs/app.log';
clearstatcache();
$logBefore = filesize($logFile);
$auditFrom = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();

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

/** Danh sách id + token_<id> trên trang duyệt / xác nhận -> body POST. */
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

function login(string $base, string $jar, string $user): bool
{
    @unlink($jar);
    $page = http($jar, 'GET', $base . '/login');

    return str_ends_with(http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => $user, 'password' => 'Password@123'])['location'], '/dashboard');
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

$periodId = 0;
$fx = e2e_fixture_create($pdo, $runId);
$tp = e2e_pick_period($pdo, 2092); // kỳ test riêng: tháng / năm không trùng kỳ người dùng, không công tơ nào bị chặn CSĐ
$month = $tp['month'];
$year = $tp['year'];
$M = $fx['meter'];   // id công tơ seed (1..8) => id công tơ fixture
$A = $fx['apt'];     // 'BT1' / 'S01' / 'CC-2305' / 'CC-2306' => id căn fixture
$aptIn = implode(',', array_values($A));
try {
    foreach (['bql' => 'bql01', 'kt' => 'kythuat01', 'kto' => 'ketoan01'] as $who => $user) {
        check('0 LOGIN', "Đăng nhập {$user}", login($base, $jar($who), $user));
    }

    // ================================================================ 1. BQL tạo kỳ (FN-04.01)
    $f = http($jar('bql'), 'GET', $base . '/periods/create');
    $r = http($jar('bql'), 'POST', $base . '/periods', ['csrf_token' => csrfFrom($f['body']), 'month' => (string) $month, 'year' => (string) $year]);
    $periodId = preg_match('#/periods/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
    $fx['periods'][] = $periodId;
    $code = $tp['code'];
    check('1 PERIOD', "BQL tạo kỳ {$code} (Mở)", $periodId > 0 && q($pdo, 'SELECT status FROM billing_periods WHERE id = ?', [$periodId]) === 'mo');

    // ================================================================ 2. KT nhập chỉ số 8 công tơ fixture qua web (FN-04.02); công tơ thật khác CL = 0 trong kỳ test
    $csc = [1 => '1686', 2 => '232', 3 => '800', 4 => '160', 5 => '600', 6 => '74', 7 => '0', 8 => '0'];
    foreach ($csc as $seedId => $value) {
        $page = http($jar('kt'), 'GET', $base . "/periods/{$periodId}/readings/create?meter_id={$M[$seedId]}");
        http($jar('kt'), 'POST', $base . "/periods/{$periodId}/readings", ['csrf_token' => csrfFrom($page['body']), 'meter_id' => (string) $M[$seedId],
            'current_reading' => $value, 'consumption' => '99999', 'note' => "E2E {$runId}"]);
    }
    $others = e2e_fill_other_meters($pdo, $fx, $periodId);
    // Khoá kỳ (BR: mọi công tơ đang hoạt động có chỉ số) cần cả công tơ thật; công tơ thật bị chặn CSĐ (B7) -> bỏ qua các bước cần khoá,
    // báo MỘT lỗi rõ "PHỤ THUỘC DỮ LIỆU THẬT" (bước khoá luôn được kiểm trên DB tạm: tests/run_core_e2e_isolated.sh)
    $realBlocked = $others['blocked'];
    $cl = $pdo->query("SELECT meter_id, consumption FROM meter_readings WHERE billing_period_id = {$periodId}")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pending = array_keys($cl);
    check('2 READING', 'KT nhập 8 chỉ số fixture; CL do server tính (bỏ qua consumption gửi giả): BT1 điện 343, nước 16; CC-2306 = 0',
        count($cl) === 8 + $others['created'] && ($cl[$M[1]] ?? '') === '343.00' && ($cl[$M[2]] ?? '') === '16.00' && ($cl[$M[7]] ?? '') === '0.00');
    $bl = http($jar('kto'), 'GET', $base . "/periods/{$periodId}/billing");
    http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/calculate", ['csrf_token' => csrfFrom($bl['body'])]);
    check('2 READING', 'Kế toán tính phí khi chỉ số còn Nháp → không tạo bản tính (BR-11)', (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ?', [$periodId]) === 0);

    // ================================================================ 3. BQL duyệt (FN-04.08)
    $ap = http($jar('bql'), 'GET', $base . "/periods/{$periodId}/approvals");
    http($jar('bql'), 'POST', $base . "/periods/{$periodId}/approvals", bulkFrom($ap['body'], 'reading_ids'));
    check('3 APPROVE', 'BQL duyệt theo lô → mọi chỉ số Đã xác nhận', (int) q($pdo, "SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ? AND status = 'da_xac_nhan'", [$periodId]) === count($pending));

    // ================================================================ 4. KTo tính phí + xác nhận (FN-05.02 - 05.06)
    $bl = http($jar('kto'), 'GET', $base . "/periods/{$periodId}/billing");
    http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/calculate", ['csrf_token' => csrfFrom($bl['body'])]);
    $ch = $pdo->query("SELECT apartment_id, CONCAT(electricity_amount, '|', electricity_vat_amount, '|', water_amount, '|', total_arising) FROM apartment_charges WHERE billing_period_id = {$periodId}")->fetchAll(PDO::FETCH_KEY_PAIR);
    check('4 BILLING', 'BT1: 804.350 + VAT 64.348 (= 868.698), nước 185.120, tổng phát sinh 1.950.058', ($ch[$A['BT1']] ?? '') === '804350.00|64348.00|185120.00|1950058.00', (string) ($ch[$A['BT1']] ?? ''));
    check('4 BILLING', 'Tổng tòa fixture = 4.773.552 (S01 1.784.428, CC-2305 797.356, CC-2306 241.710)', q($pdo, "SELECT SUM(total_arising) FROM apartment_charges WHERE billing_period_id = ? AND apartment_id IN ({$aptIn})", [$periodId]) === '4773552.00');
    check('4 BILLING', 'Chi tiết bậc BT1 lưu đúng (Σ = 804.350, 5 dòng gắn meter_reading_id)', q($pdo, "SELECT CONCAT(COUNT(*), '|', SUM(amount)) FROM charge_tier_details d JOIN apartment_charges c ON c.id = d.apartment_charge_id WHERE c.billing_period_id = ? AND c.apartment_id = ? AND d.utility_type = 'dien'", [$periodId, $A['BT1']]) === '5|804350.00');
    $rv = http($jar('kto'), 'GET', $base . "/periods/{$periodId}/billing/review");
    http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/confirm", bulkFrom($rv['body'], 'charge_ids'));
    $allCharges = (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ?', [$periodId]);
    check('4 BILLING', 'Kế toán xác nhận theo lô → 4 bản tính fixture (và mọi bản tính của kỳ test) Đã xác nhận', (int) q($pdo, "SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ? AND apartment_id IN ({$aptIn}) AND status = 'da_xac_nhan'", [$periodId]) === 4
        && (int) q($pdo, "SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ? AND status = 'da_xac_nhan'", [$periodId]) === $allCharges);

    // ================================================================ 5. FN-06: baseline + mục tiêu + so sánh (BQL)
    $bf = http($jar('bql'), 'GET', $base . '/baselines/create');
    http($jar('bql'), 'POST', $base . '/baselines', ['csrf_token' => csrfFrom($bf['body']), 'intent' => 'save', 'scope' => 'meter:' . $M[1], 'utility_type' => 'dien', 'method' => 'nhap_tay',
        'manual_value' => '300', 'effective_date' => sprintf('%04d-%02d-01', $year, $month)]);
    $tf = http($jar('bql'), 'GET', $base . '/targets/create');
    http($jar('bql'), 'POST', $base . '/targets', ['csrf_token' => csrfFrom($tf['body']), 'scope' => 'building:' . $fx['building'], 'utility_type' => 'dien', 'billing_period_id' => (string) $periodId, 'target_value' => '700']);
    check('5 FN-06', 'BQL lập baseline BT1-DIEN (fixture) = 300 và mục tiêu điện tòa fixture = 700', (int) q($pdo, "SELECT COUNT(*) FROM baselines WHERE scope_type = 'meter' AND scope_id = ? AND manual_value = 300", [$M[1]]) === 1
        && (int) q($pdo, "SELECT COUNT(*) FROM consumption_targets WHERE scope_type = 'building' AND scope_id = ? AND billing_period_id = ?", [$fx['building'], $periodId]) === 1);
    $cp = http($jar('bql'), 'GET', $base . "/comparisons?period_id={$periodId}&scope_type=meter&utility_type=dien");
    check('5 FN-06', 'So sánh: BT1-DIEN 343 vs baseline 300 → +43, +14,33%, ▲ Tăng', str_contains($cp['body'], '+14,33%') && str_contains($cp['body'], '▲ Tăng'));
    $tk = http($jar('bql'), 'GET', $base . "/targets/tracking?period_id={$periodId}");
    check('5 FN-06', 'Theo dõi mục tiêu: thực tế 643 ≤ 700 → Đạt, 91,86%', str_contains($tk['body'], '✔ Đạt') && str_contains($tk['body'], '91,86%'));

    // ================================================================ 6. FN-07: rule → đánh giá → review → kiểm tra → xác nhận
    $rf = http($jar('bql'), 'GET', $base . '/alert-rules/create');
    http($jar('bql'), 'POST', $base . '/alert-rules', ['csrf_token' => csrfFrom($rf['body']), 'name' => "E2E baseline > 10% {$runId}", 'measure_type' => 'tieu_thu', 'utility_type' => 'dien',
        'compare_base' => 'baseline', 'operator' => 'gt', 'threshold_value' => '10.' . random_int(1, 9), 'scope_type' => 'toan_toa', 'priority_level' => 'cao']);
    $al = http($jar('bql'), 'GET', $base . '/alerts');
    http($jar('bql'), 'POST', $base . '/alerts/evaluate', ['csrf_token' => csrfFrom($al['body']), 'period_id' => (string) $periodId]);
    // chỉ cảnh báo của kỳ test trên căn fixture (quy tắc thật của người dùng trên công tơ thật không tính)
    $alerts = $pdo->query("SELECT id, meter_id, percent_change, source, status FROM alerts WHERE billing_period_id = {$periodId} AND apartment_id IN ({$aptIn})")->fetchAll();
    $alertId = (int) ($alerts[0]['id'] ?? 0);
    check('6 FN-07', 'Đánh giá ngưỡng (FN-06 baseline → quy tắc): đúng 1 cảnh báo BT1-DIEN +14,33% (quy tắc seed > 30% không sinh)', count($alerts) === 1 && (int) $alerts[0]['meter_id'] === $M[1]
        && $alerts[0]['percent_change'] === '14.33' && $alerts[0]['source'] === 'rule', json_encode($alerts));
    http($jar('bql'), 'POST', $base . '/alerts/evaluate', ['csrf_token' => csrfFrom($al['body']), 'period_id' => (string) $periodId]);
    check('6 FN-07', 'Đánh giá lại → không trùng', (int) q($pdo, "SELECT COUNT(*) FROM alerts WHERE billing_period_id = ? AND apartment_id IN ({$aptIn})", [$periodId]) === 1);
    $d = http($jar('bql'), 'GET', $base . "/alerts/{$alertId}");
    check('6 FN-07', 'Chi tiết: "Vì sao có cảnh báo?" — không kết luận nguyên nhân (BR-29)', str_contains($d['body'], 'Vì sao có cảnh báo?') && str_contains($d['body'], 'không phải kết luận')
        && !preg_match('/rò rỉ nước|công tơ (bị )?hỏng|gian lận/u', strip_tags($d['body'])));
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/review", ['csrf_token' => csrfFrom($d['body']), 'version_token' => tokenFrom($d['body']), 'priority' => 'cao', 'note' => 'E2E']);
    $d = http($jar('bql'), 'GET', $base . "/alerts/{$alertId}");
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/request-verification", ['csrf_token' => csrfFrom($d['body']), 'version_token' => tokenFrom($d['body']), 'content' => 'Đọc lại công tơ BT1-DIEN', 'assigned_to' => '2']);
    check('6 FN-07', 'BQL review → yêu cầu kiểm tra (giao kythuat01) → Cần kiểm tra lại', q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'can_kiem_tra_lai');
    $kd = http($jar('kt'), 'GET', $base . "/alerts/{$alertId}");
    http($jar('kt'), 'POST', $base . "/alerts/{$alertId}/verification-result", ['csrf_token' => csrfFrom($kd['body']), 'result_conclusion' => 'canh_bao_dung', 'rechecked_value' => '1686', 'note' => 'Đọc lại đúng']);
    check('6 FN-07', 'KT xem case được giao, gửi kết quả "Cảnh báo đúng"', $kd['code'] === 200 && q($pdo, 'SELECT status FROM inspection_cases WHERE alert_id = ?', [$alertId]) === 'da_co_ket_qua');
    $d = http($jar('bql'), 'GET', $base . "/alerts/{$alertId}");
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/confirm", ['csrf_token' => csrfFrom($d['body']), 'version_token' => tokenFrom($d['body']), 'decision' => 'confirm', 'note' => 'Tiêu thụ tăng thật']);
    check('6 FN-07', 'BQL xác nhận dữ liệu đúng → Đã xác nhận (chỉ số không đổi)', q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'da_xac_nhan'
        && q($pdo, "SELECT CONCAT(current_reading, status) FROM meter_readings WHERE billing_period_id = ? AND meter_id = ?", [$periodId, $M[1]]) === '1686.00da_xac_nhan');

    // ================================================================ 7. FN-08: khuyến nghị (trước khi đóng - cảnh báo Đã đóng không sinh mới, T3)
    $d = http($jar('bql'), 'GET', $base . "/alerts/{$alertId}");
    $statusBefore = q($pdo, 'SELECT CONCAT(status, priority, updated_at) FROM alerts WHERE id = ?', [$alertId]);
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/recommendations", ['csrf_token' => csrfFrom($d['body'])]);
    $recs = $pdo->query("SELECT recommendation_rule_id, recommendation_text FROM recommendations WHERE alert_id = {$alertId}")->fetchAll(PDO::FETCH_KEY_PAIR);
    check('7 FN-08', 'Khuyến nghị theo quy tắc seed "vượt ngưỡng 1 kỳ" → "Đề xuất kiểm tra công tơ"; không đổi trạng thái cảnh báo', ($recs[2] ?? '') === 'Đề xuất kiểm tra công tơ'
        && !isset($recs[1]) && q($pdo, 'SELECT CONCAT(status, priority, updated_at) FROM alerts WHERE id = ?', [$alertId]) === $statusBefore, json_encode($recs, JSON_UNESCAPED_UNICODE));
    $d = http($jar('bql'), 'GET', $base . "/alerts/{$alertId}");
    http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/close", ['csrf_token' => csrfFrom($d['body']), 'version_token' => tokenFrom($d['body']), 'close_reason' => 'Đã xử lý']);
    check('7 FN-08', 'BQL đóng cảnh báo; khuyến nghị lịch sử vẫn hiển thị', q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'da_dong'
        && str_contains(http($jar('bql'), 'GET', $base . "/alerts/{$alertId}")['body'], 'Đề xuất kiểm tra công tơ'));

    // ================================================================ 8. KTo khoá kỳ (FN-05.07)
    $pp = http($jar('kto'), 'GET', $base . "/periods/{$periodId}");
    if ($realBlocked !== []) {
        check('8 LOCK', 'PHỤ THUỘC DỮ LIỆU THẬT (không phải lỗi code): không khoá được kỳ test vì ' . count($realBlocked) . ' công tơ thật bị chặn CSĐ — bỏ qua bước khoá / sau khoá (đã kiểm trên DB tạm)',
            false, json_encode($realBlocked, JSON_UNESCAPED_UNICODE));
        throw new RuntimeException('skip-lock');
    }
    check('8 LOCK', 'Trang kỳ (Kế toán): đủ điều kiện khóa', str_contains($pp['body'], 'Đủ điều kiện khóa kỳ'));
    http($jar('kto'), 'POST', $base . "/periods/{$periodId}/lock", ['csrf_token' => csrfFrom($pp['body'])]);
    check('8 LOCK', 'Khóa kỳ → Đã khóa', q($pdo, 'SELECT status FROM billing_periods WHERE id = ?', [$periodId]) === 'da_khoa');

    // ================================================================ 9. FN-09: dashboard / báo cáo / phiếu / CSV phản ánh đúng
    $db = http($jar('bql'), 'GET', $base . "/dashboard?period_id={$periodId}&building_id={$fx['building']}")['body'];
    check('9 FN-09', 'Dashboard BQL: điện 643, nước 58, chi phí 2.891.712 đ "Chính thức", tỷ lệ đạt mục tiêu 100%, khuyến nghị 1', str_contains($db, 'stat-card__value">643<') && str_contains($db, 'stat-card__value">58<')
        && str_contains($db, '2.891.712 đ') && str_contains($db, 'Chính thức (kỳ đã khóa)') && str_contains($db, 'stat-card__value">100%<') && preg_match('/Khuyến nghị đã sinh \(FN-08\)<\/div>\s*<div class="stat-card__value">1</', $db) === 1);
    check('9 FN-09', 'Dashboard: cảnh báo đã đóng không còn trong "đang mở"; so baseline hiển thị', preg_match('/Cảnh báo đang mở<\/div>\s*<div class="stat-card__value">0</', $db) === 1);
    $rp = http($jar('kto'), 'GET', $base . "/reports/utility?period_id={$periodId}&building_id={$fx['building']}")['body'];
    check('9 FN-09', 'Báo cáo (Kế toán, lọc tòa fixture): 4 căn chính thức, tổng 4.773.552 đ', str_contains($rp, 'Chỉ cộng 4 bản chính thức') && str_contains($rp, '4.773.552 đ'));
    $csv = http($jar('kto'), 'GET', $base . "/reports/utility/export?period_id={$periodId}&building_id={$fx['building']}")['body'];
    check('9 FN-09', 'CSV: dòng tổng 643.00 / 58.00 / 4773552.00', str_contains($csv, ',643.00,58.00,') && str_contains($csv, '4773552.00'));
    $bt1Charge = (int) q($pdo, 'SELECT id FROM apartment_charges WHERE billing_period_id = ? AND apartment_id = ?', [$periodId, $A['BT1']]);
    $notice = http($jar('kto'), 'GET', $base . "/charges/{$bt1Charge}/notice")['body'];
    check('9 FN-09', 'Phiếu BT1: tổng phải thu 1.950.058 đ, cơ sở tính phí (80 m², 1 ô tô)', str_contains($notice, '1.950.058 đ') && str_contains($notice, '80 m²') && str_contains($notice, 'Gửi ô tô'));

    // ================================================================ 10. Sau khoá: thay đổi bị chặn
    $readingId = (int) q($pdo, 'SELECT id FROM meter_readings WHERE billing_period_id = ? AND meter_id = ?', [$periodId, $M[1]]);
    $e = http($jar('kt'), 'GET', $base . "/readings/{$readingId}/edit");
    http($jar('kt'), 'POST', $base . "/readings/{$readingId}/update", ['csrf_token' => csrfFrom($e['body']) ?: csrfFrom(http($jar('kt'), 'GET', $base . '/dashboard')['body']), 'current_reading' => '1700', 'note' => 'sau khóa', 'version_token' => tokenFrom($e['body'])]);
    http($jar('kto'), 'POST', $base . "/periods/{$periodId}/billing/calculate", ['csrf_token' => csrfFrom($pp['body']), 'apartment_id' => (string) $A['BT1']]);
    $tok = csrfFrom(http($jar('kto'), 'GET', $base . '/dashboard')['body']);
    http($jar('kto'), 'POST', "{$base}/charges/{$bt1Charge}/old-debt", ['csrf_token' => $tok, 'old_debt_amount' => '1', 'reason' => 'x', 'version_token' => 'x']);
    check('10 LOCKED', 'Sau khóa: KT sửa chỉ số, KTo tính lại / sửa nợ cũ đều bị chặn — dữ liệu không đổi', q($pdo, 'SELECT current_reading FROM meter_readings WHERE id = ?', [$readingId]) === '1686.00'
        && q($pdo, 'SELECT total_due FROM apartment_charges WHERE id = ?', [$bt1Charge]) === '1950058.00' && q($pdo, 'SELECT status FROM billing_periods WHERE id = ?', [$periodId]) === 'da_khoa');

    // ================================================================ 11. Audit
    // chỉ audit thuộc phạm vi test: loại hành động có mặt trong lượt chạy; số lượng đếm theo đúng bản ghi của kỳ test (không lẫn thao tác người dùng)
    $acts = $pdo->query("SELECT action, COUNT(*) FROM audit_logs WHERE id > {$auditFrom} GROUP BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
    $acts['CREATE_METER_READING'] = (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'CREATE_METER_READING' AND entity_type = 'meter_reading'
        AND entity_id IN (SELECT id FROM meter_readings WHERE billing_period_id = ?)", [$auditFrom, $periodId]);
    $acts['CALCULATE_APARTMENT_CHARGE'] = (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'CALCULATE_APARTMENT_CHARGE' AND entity_type = 'apartment_charge'
        AND entity_id IN (SELECT id FROM apartment_charges WHERE billing_period_id = ?)", [$auditFrom, $periodId]);
    $need = ['CREATE_BILLING_PERIOD', 'CREATE_METER_READING', 'APPROVE_METER_READING', 'CALCULATE_APARTMENT_CHARGE', 'CALCULATE_BILLING_PERIOD', 'CONFIRM_APARTMENT_CHARGE', 'CREATE_BASELINE',
        'CREATE_CONSUMPTION_TARGET', 'CREATE_ALERT_RULE', 'EVALUATE_ALERT_RULES', 'CREATE_ALERT_AUTO', 'REVIEW_ALERT', 'REQUEST_ALERT_VERIFICATION', 'UPDATE_ALERT_VERIFICATION', 'CONFIRM_ALERT_DATA',
        'GENERATE_RECOMMENDATIONS', 'CLOSE_ALERT', 'LOCK_BILLING_PERIOD', 'EXPORT_UTILITY_REPORT', 'login_success'];
    $missing = array_values(array_diff($need, array_keys($acts)));
    check('11 AUDIT', 'Đủ ' . count($need) . ' loại audit cho cả luồng; CREATE_METER_READING = ' . count($pending) . ', CALCULATE_APARTMENT_CHARGE = ' . $allCharges, $missing === []
        && (int) $acts['CREATE_METER_READING'] === count($pending) && (int) $acts['CALCULATE_APARTMENT_CHARGE'] === $allCharges, json_encode($missing));
    clearstatcache();
    check('11 AUDIT', 'app.log: không có lỗi PHP trong cả luồng', !preg_match('/PHP Error|Fatal Error|Uncaught/i', (string) file_get_contents($logFile, false, null, $logBefore)));
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'skip-lock') {
        throw $e;
    }
} finally {
    e2e_cleanup($pdo, $fx); // kỳ test + tòa / căn / công tơ fixture (+ cảnh báo, khuyến nghị, mục tiêu, baseline gắn vào chúng)
    e2e_rules_cleanup($pdo, $runId); // quy tắc "E2E … <runId>" + cảnh báo của chúng - không đụng quy tắc / cảnh báo người dùng
    // chỉ đếm dữ liệu thuộc phạm vi test (id fixture / id kỳ test / quy tắc mang mã lượt chạy) - không đếm dữ liệu người dùng
    $left = e2e_leftovers($pdo, $fx) + e2e_rules_leftovers($pdo, $runId)
        + (int) q($pdo, "SELECT COUNT(*) FROM baselines WHERE scope_type = 'meter' AND scope_id IN (" . implode(',', array_map('intval', $M)) . ')');
    check('12 CLEANUP', 'Đã xóa toàn bộ dữ liệu E2E (kỳ test, fixture, chỉ số, bản tính, baseline, mục tiêu, quy tắc, cảnh báo, khuyến nghị); audit giữ lại', $left === 0, "còn {$left}");
    foreach (['bql', 'kt', 'kto'] as $who) {
        @unlink($jar($who));
    }
}

$failed = 0;
foreach ($results as [$g, $n, $ok, $d]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-10s %s%s\n", $ok ? 'PASS' : 'FAIL', $g, $n, $ok || $d === '' ? '' : "  -> {$d}");
}
printf("\n%d/%d PASS (Core E2E qua %s, run %s)\n", count($results) - $failed, count($results), $base, $runId);
exit($failed === 0 ? 0 : 1);
