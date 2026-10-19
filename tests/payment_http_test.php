<?php

declare(strict_types=1);

/**
 * Payment module - test HTTP qua php -S trên DB TẠM (router test: email luôn "log", KHÔNG gửi thật).
 *   php tests/payment_http_test.php <base_url_callback_bật> <base_url_callback_tắt> <db_tạm> <mock_secret> <mock_bank_app_url> <mock_bank_app_url_trỏ_sai>
 * Mock Bank là ứng dụng ĐỘC LẬP (tools/mock-bank, php -S riêng) - gửi callback ký HMAC tới server A.
 * Server A: MOCK_BANK_ENABLED=1 + MOCK_BANK_SECRET; server B: không có biến -> Mock Bank tắt (như production mặc định).
 */

[, $baseA, $baseB, $db, $secret, $toolA, $toolBad] = $argv + [null, '', '', '', '', '', ''];
$toolA = rtrim($toolA, '/');
$toolBad = rtrim($toolBad, '/');
$baseA = rtrim($baseA, '/');
$baseB = rtrim($baseB, '/');
if ($baseA === '' || $baseB === '' || $toolA === '' || $toolBad === '' || $db === '' || in_array($db, ['energy_dss', 'energy_dss_v2'], true) || strlen($secret) < 16) {
    fwrite(STDERR, "Dùng: php tests/payment_http_test.php <base_bật> <base_tắt> <db_tạm> <secret>\n");
    exit(2);
}
$pdo = new PDO("mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = static fn (string $sql) => $pdo->query($sql)->fetchColumn();
$tmp = sys_get_temp_dir() . '/payment_http_' . getmypid();
@mkdir($tmp);
$jar = static fn (string $n): string => "{$tmp}/{$n}.jar";

$pass = 0;
$fail = 0;
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . str_pad($g, 8) . " {$n}" . ($ok || $d === '' ? '' : "  -> {$d}") . "\n";
}
function http(string $jar, string $method, string $url, $post = [], array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post) ? $post : http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $r = ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'location' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL), 'body' => $body];
    curl_close($ch);

    return $r;
}
function csrf(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}
function login(string $base, string $jar, string $user): bool
{
    @unlink($jar);
    $page = http($jar, 'GET', $base . '/login');

    return str_ends_with(http($jar, 'POST', $base . '/login', ['csrf_token' => csrf($page['body']), 'username' => $user, 'password' => 'Password@123'])['location'], '/dashboard');
}
function phpError(string $b): bool
{
    return (bool) preg_match('/Fatal error|Uncaught|Warning:|Notice:|SQLSTATE|PDOException/', $b);
}
$callback = static function (string $base, array $payload, ?string $sig = null) use ($secret): array {
    $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    $r = http('/dev/null', 'POST', $base . '/payments/callback', $body, ['Content-Type: application/json', 'X-Signature: ' . ($sig ?? hash_hmac('sha256', $body, $secret))]);

    return $r + ['json' => json_decode($r['body'], true)];
};

$pdo->exec("UPDATE residents SET email = 'pham.van.a@example.invalid' WHERE apartment_id = 1");
$due1 = (string) $q('SELECT total_due FROM apartment_charges WHERE id = 1');
$due3 = (string) $q('SELECT total_due FROM apartment_charges WHERE id = 3');

$G = 'LOGIN';
foreach (['bql' => 'bql01', 'kt' => 'kythuat01', 'kto' => 'ketoan01'] as $k => $u) {
    check($G, "Đăng nhập {$u} (server A)", login($baseA, $jar($k), $u));
}

// ---------------------------------------------------------------- RBAC
$G = 'RBAC';
$codes = [];
foreach (['kt' => [403, 403], 'bql' => [200, 200], 'kto' => [200, 200]] as $who => $expect) {
    foreach (['/payments', '/payments/review'] as $i => $p) {
        $r = http($jar($who), 'GET', $baseA . $p);
        $codes[] = "{$who} {$p} {$r['code']}";
        if ($r['code'] !== $expect[$i] || phpError($r['body'])) {
            $codes[] = 'SAI';
        }
    }
}
check($G, 'Kỹ thuật 403; BQL + Kế toán 200 (dashboard thanh toán, cần kiểm tra); không lỗi PHP', !in_array('SAI', $codes, true), implode(', ', $codes));
$ktTok = csrf(http($jar('kt'), 'GET', $baseA . '/dashboard')['body']);
$bqlTok = csrf(http($jar('bql'), 'GET', $baseA . '/dashboard')['body']);
check($G, 'POST khớp / bỏ qua: Kỹ thuật 403, BQL 403 (chỉ Kế toán xử lý)',
    http($jar('kt'), 'POST', $baseA . '/payments/1/ignore', ['csrf_token' => $ktTok, 'note' => 'x'])['code'] === 403
    && http($jar('bql'), 'POST', $baseA . '/payments/1/match', ['csrf_token' => $bqlTok, 'ref' => 'EDSS000001', 'note' => 'x'])['code'] === 403);
$mbCodes = [];
foreach (['kt', 'bql', 'kto', 'anonA'] as $who) {
    foreach ([['GET', '/mock-bank'], ['POST', '/mock-bank/send']] as [$m, $p]) {
        $mbCodes[] = $who . ' ' . $m . ' ' . http($jar($who), $m, $baseA . $p, ['csrf_token' => $ktTok])['code'];
    }
}
check($G, 'Mock Bank KHÔNG thuộc Energy DSS: /mock-bank, /mock-bank/send → 404 với mọi vai trò kể cả khi callback đang bật',
    count(array_filter($mbCodes, static fn ($c) => !str_ends_with($c, ' 404'))) === 0, implode(', ', $mbCodes));
$sideKto = http($jar('kto'), 'GET', $baseA . '/dashboard')['body'];
$payKto = http($jar('kto'), 'GET', $baseA . '/payments')['body'];
// Sidebar riêng của Kế toán: đúng thứ tự nhóm / mục, ẩn các mục không thuộc việc Kế toán (route vẫn còn - chỉ là hiển thị)
preg_match('#<aside class="app-sidebar".*?</aside>#s', $sideKto, $ktAside);
preg_match_all('#nav-group__label">([^<]+)|app-sidebar__icon">[^<]*</span> ([^<]+?)\s*(?:<|$)#u', $ktAside[0] ?? '', $ktM, PREG_SET_ORDER);
$ktOrder = array_map(static fn ($m) => html_entity_decode(($m[1] ?? '') !== '' ? '#' . $m[1] : trim($m[2])), $ktM);
check($G, 'Sidebar Kế toán đúng thứ tự: Dashboard / Tính phí & chốt kỳ / Thanh toán / Thông báo / Báo cáo / Biểu giá & phí / Tra cứu; ẩn Tòa nhà, Khu vực, Công tơ, Kỳ ghi chỉ số, Mục tiêu, Cảnh báo, Quy tắc ngưỡng (route vẫn 200)',
    $ktOrder === ['Dashboard', '#Tính phí & chốt kỳ', 'Tính phí theo kỳ', '#Thanh toán', 'Theo dõi thanh toán', 'Cần kiểm tra', '#Thông báo', 'Hộp thư đi', '#Báo cáo', 'Báo cáo tiện ích',
        '#Biểu giá & phí', 'Biểu giá điện/nước', 'Phí cố định & gửi xe', 'Đề xuất biểu giá', '#Tra cứu', 'Căn hộ', 'Tra cứu chỉ số']
    && http($jar('kto'), 'GET', $baseA . '/alerts')['code'] === 200 && http($jar('kto'), 'GET', $baseA . '/meters')['code'] === 200, implode(' | ', $ktOrder));
check($G, 'Menu "Thanh toán": BQL / Kế toán cùng nhãn "Theo dõi thanh toán", "Hộp thư đi"; Kỹ thuật không thấy; không có Mock Bank ở bất kỳ đâu trong Energy DSS',
    preg_match('#💳</span> Theo dõi thanh toán\s*</a>#u', $sideBql = http($jar('bql'), 'GET', $baseA . '/dashboard')['body']) === 1 && str_contains($sideKto, '💳</span> Theo dõi thanh toán')
    && str_contains($sideBql, '✉️</span> Hộp thư đi<') && str_contains($sideKto, '✉️</span> Hộp thư đi<')
    && !str_contains($sideBql . $sideKto, 'Dashboard thanh toán</a>') && !str_contains($sideBql . $sideKto, '✉️</span> Hộp thư đi (email)')
    && !str_contains($kd = http($jar('kt'), 'GET', $baseA . '/dashboard')['body'], 'Dashboard thanh toán') && !str_contains($kd, 'Theo dõi thanh toán')
    && !str_contains($sideKto, '/mock-bank') && !str_contains($sideKto, 'Mock Bank') && !str_contains($payKto, '/mock-bank') && !str_contains($payKto, 'Mock Bank'));

// ---------------------------------------------------------------- Mock Bank ĐỘC LẬP (tools/mock-bank) -> callback HMAC của Energy DSS
$G = 'MOCKAPP';
$tool = http($jar('tool'), 'GET', $toolA . '/');
check($G, 'Mock Bank độc lập mở được KHÔNG cần đăng nhập Energy DSS: đủ trường mã thanh toán, số tiền, mã giao dịch, người chuyển, SUCCESS, nút "Gửi callback"',
    $tool['code'] === 200 && str_contains($tool['body'], 'name="ref"') && str_contains($tool['body'], 'name="amount"') && str_contains($tool['body'], 'name="transaction_id"')
    && str_contains($tool['body'], 'name="payer_name"') && str_contains($tool['body'], '>SUCCESS<') && str_contains($tool['body'], '>Gửi callback</button>')
    && !str_contains($tool['body'], 'csrf_token') && !str_contains($tool['body'], 'Đăng nhập'));
$toolTok = (static function (string $html): string { preg_match('/name="csrf" value="([^"]+)"/', $html, $m); return $m[1] ?? ''; })($tool['body']);
$r = http($jar('tool'), 'POST', $toolA . '/', ['ref' => 'EDSS000001', 'amount' => $due1, 'transaction_id' => 'MB-NOCSRF', 'payer_name' => 'x']);
check($G, 'Gửi không có token form → bị từ chối, không gọi callback, không ghi', str_contains($r['body'], 'data-result="error"') && (int) $q("SELECT COUNT(*) FROM payment_transactions WHERE transaction_id = 'MB-NOCSRF'") === 0);
$amountVn = number_format((float) $due1, 0, ',', '.');
$r = http($jar('tool'), 'POST', $toolA . '/', ['csrf' => $toolTok, 'ref' => 'EDSS000001', 'amount' => $amountVn, 'transaction_id' => 'MB-0001', 'payer_name' => 'Pham Van A']);
check($G, "Gửi callback (số tiền dạng {$amountVn}) → Energy DSS 200, \"Khớp tự động → ĐÃ THANH TOÁN\"; DB matched, payload status SUCCESS",
    str_contains($r['body'], 'data-result="ok"') && str_contains($r['body'], 'ĐÃ THANH TOÁN') && $q("SELECT match_status FROM payment_transactions WHERE transaction_id = 'MB-0001'") === 'matched'
    && str_contains((string) $q("SELECT raw_payload FROM payment_transactions WHERE transaction_id = 'MB-0001'"), '"status":"SUCCESS"'));
check($G, 'Email xác nhận thanh toán trong hộp thư đi (transport log - không gửi thật), đúng 1 thư',
    $q("SELECT CONCAT(COUNT(*), '|', MAX(transport), '|', MAX(status)) FROM email_notifications WHERE apartment_charge_id = 1 AND notification_type = 'payment_confirmation'") === '1|log|sent');
$r = http($jar('tool'), 'POST', $toolA . '/', ['csrf' => $toolTok, 'ref' => 'EDSS000001', 'amount' => $due1, 'transaction_id' => 'MB-0001', 'payer_name' => '']);
check($G, 'Gửi lại CÙNG mã giao dịch → "không xử lý lại (idempotent)", vẫn 1 bản ghi, vẫn 1 email; lịch sử gửi có 2 dòng', str_contains($r['body'], 'idempotent')
    && (int) $q("SELECT COUNT(*) FROM payment_transactions WHERE transaction_id = 'MB-0001'") === 1
    && (int) $q("SELECT COUNT(*) FROM email_notifications WHERE notification_type = 'payment_confirmation'") === 1 && substr_count($r['body'], '<code>MB-0001</code>') >= 2);
$bad = http($jar('toolBad'), 'GET', $toolBad . '/');
$badTok = (static function (string $html): string { preg_match('/name="csrf" value="([^"]+)"/', $html, $m); return $m[1] ?? ''; })($bad['body']);
$badPost = http($jar('toolBad'), 'POST', $toolBad . '/', ['csrf' => $badTok, 'ref' => 'EDSS000004', 'amount' => '1', 'transaction_id' => 'MB-BADURL', 'payer_name' => '']);
check($G, 'Mock Bank trỏ callback ra ngoài máy local → báo lỗi cấu hình, nút bị khoá, không gửi', str_contains($bad['body'], 'data-config-error') && str_contains($bad['body'], 'disabled')
    && str_contains($badPost['body'], 'data-result="error"') && (int) $q("SELECT COUNT(*) FROM payment_transactions WHERE transaction_id = 'MB-BADURL'") === 0);

// ---------------------------------------------------------------- Callback HTTP (máy gọi máy)
$G = 'CALLBACK';
$ok = $callback($baseA, ['transaction_id' => 'CB-0001', 'amount' => '1000', 'content' => 'EDSS000002']);
check($G, 'Chữ ký đúng → 200 JSON {ok, match_status: amount_mismatch, charge_ref EDSS000002}', $ok['code'] === 200 && ($ok['json']['ok'] ?? false) === true
    && ($ok['json']['match_status'] ?? '') === 'amount_mismatch' && ($ok['json']['charge_ref'] ?? '') === 'EDSS000002', $ok['body']);
$dup = $callback($baseA, ['transaction_id' => 'CB-0001', 'amount' => '1000', 'content' => 'EDSS000002']);
check($G, 'Callback lặp → 200 duplicate=true, không ghi thêm', ($dup['json']['duplicate'] ?? false) === true && (int) $q("SELECT COUNT(*) FROM payment_transactions WHERE transaction_id = 'CB-0001'") === 1);
$bad = $callback($baseA, ['transaction_id' => 'CB-BAD', 'amount' => '1', 'content' => 'EDSS000002'], str_repeat('0', 64));
check($G, 'Chữ ký sai → 401 JSON, không ghi', $bad['code'] === 401 && ($bad['json']['ok'] ?? true) === false && (int) $q("SELECT COUNT(*) FROM payment_transactions WHERE transaction_id = 'CB-BAD'") === 0);
$big = http('/dev/null', 'POST', $baseA . '/payments/callback', str_repeat('x', 9000), ['Content-Type: application/json']);
check($G, 'Payload > 8KB → 413', $big['code'] === 413);
$unk = $callback($baseA, ['transaction_id' => 'CB-0002', 'amount' => $due3, 'content' => 'chuyen khoan khong ghi ma']);
check($G, 'Không rõ mã → 200 unknown_ref (vào hàng chờ kiểm tra)', ($unk['json']['match_status'] ?? '') === 'unknown_ref');

// ---------------------------------------------------------------- Review (Kế toán)
$G = 'REVIEW';
$rv = http($jar('kto'), 'GET', $baseA . '/payments/review')['body'];
check($G, 'Hàng chờ: CB-0001 (sai số tiền) + CB-0002 (không rõ mã), có form khớp / bỏ qua', str_contains($rv, 'data-review-txn="CB-0001"') && str_contains($rv, 'data-review-txn="CB-0002"')
    && str_contains($rv, 'data-review-match') && str_contains($rv, 'data-review-ignore'));
check($G, 'BQL xem hàng chờ nhưng không có form xử lý', !str_contains(http($jar('bql'), 'GET', $baseA . '/payments/review')['body'], 'data-review-match'));
$id1 = (int) $q("SELECT id FROM payment_transactions WHERE transaction_id = 'CB-0001'");
$id2 = (int) $q("SELECT id FROM payment_transactions WHERE transaction_id = 'CB-0002'");
http($jar('kto'), 'POST', $baseA . "/payments/{$id1}/ignore", ['csrf_token' => csrf($rv), 'note' => '']);
check($G, 'Bỏ qua thiếu lý do → lỗi, giữ trạng thái', str_contains(http($jar('kto'), 'GET', $baseA . '/payments/review')['body'], 'lý do') && $q("SELECT match_status FROM payment_transactions WHERE id = {$id1}") === 'amount_mismatch');
$rv = http($jar('kto'), 'GET', $baseA . '/payments/review')['body'];
http($jar('kto'), 'POST', $baseA . "/payments/{$id1}/ignore", ['csrf_token' => csrf($rv), 'note' => 'Đã hoàn tiền cho cư dân']);
http($jar('kto'), 'POST', $baseA . "/payments/{$id2}/match", ['csrf_token' => csrf($rv), 'ref' => 'EDSS000003', 'note' => 'Chủ hộ CC-2305 xác nhận']);
check($G, 'Bỏ qua có lý do → ignored; khớp thủ công đúng số tiền → manual_matched, CC-2305 đã thanh toán; hàng chờ trống',
    $q("SELECT match_status FROM payment_transactions WHERE id = {$id1}") === 'ignored' && $q("SELECT match_status FROM payment_transactions WHERE id = {$id2}") === 'manual_matched'
    && str_contains(http($jar('kto'), 'GET', $baseA . '/payments/review')['body'], 'Không có giao dịch cần kiểm tra'));

// ---------------------------------------------------------------- Dashboard + phiếu web
$G = 'DASH';
$d = http($jar('bql'), 'GET', $baseA . '/payments?period_id=1')['body'];
preg_match('#data-payment-row="EDSS000001">.*?</tr>#s', $d, $row1);
preg_match('#data-payment-row="EDSS000004">.*?</tr>#s', $d, $row4);
check($G, 'Dashboard kỳ 06/2024: 4 thẻ (phải thu / đã thu / chưa thu / cần kiểm tra), BT1 + CC-2305 Đã thanh toán, CC-2306 Chưa thanh toán',
    str_contains($d, 'data-kpi="due"') && str_contains($d, 'data-kpi="collected"') && str_contains($d, 'data-kpi="outstanding"') && str_contains($d, 'data-kpi="review"')
    && str_contains($row1[0] ?? '', 'Đã thanh toán') && str_contains($row4[0] ?? '', 'Chưa thanh toán') && !phpError($d));
check($G, 'Giao dịch gần nhất có MB-0001, CB-0001, CB-0002 + kết quả đối soát', str_contains($d, 'MB-0001') && str_contains($d, 'CB-0001') && str_contains($d, 'Khớp thủ công'));
$n = http($jar('kto'), 'GET', $baseA . '/charges/1/notice')['body'];
check($G, 'Phiếu web BT1: "Mã thanh toán … EDSS000001" + trạng thái Đã thanh toán', str_contains($n, 'data-payment-ref="EDSS000001"') && str_contains($n, 'Đã thanh toán') && !phpError($n));
$pp = http($jar('kto'), 'GET', $baseA . '/periods/1')['body'];
check($G, 'Gửi Gmail phiếu phí hàng loạt KHÔNG bị email xác nhận thanh toán làm "Đã gửi Gmail" (nút gửi vẫn hiện)', str_contains($pp, 'data-bill-email-send') && !str_contains($pp, 'data-bill-email-done'));

// ---------------------------------------------------------------- Mở lại kỳ có thanh toán
$G = 'REOPEN';
$pp = http($jar('bql'), 'GET', $baseA . '/periods/1')['body'];
check($G, 'Hộp thoại Mở lại kỳ: cảnh báo thanh toán (2 căn) + ô xác nhận bắt buộc', str_contains($pp, 'data-reopen-payment-warning') && str_contains($pp, '2 căn') && str_contains($pp, 'name="confirm_payments"'));
http($jar('bql'), 'POST', $baseA . '/periods/1/reopen', ['csrf_token' => csrf($pp), 'reopen_reason' => 'Sửa chỉ số']);
check($G, 'Mở lại KHÔNG tích xác nhận → bị yêu cầu xác nhận, kỳ vẫn Đã khoá', $q('SELECT status FROM billing_periods WHERE id = 1') === 'da_khoa'
    && str_contains(http($jar('bql'), 'GET', $baseA . '/periods/1')['body'], 'tích xác nhận'));
$pp = http($jar('bql'), 'GET', $baseA . '/periods/1')['body'];
http($jar('bql'), 'POST', $baseA . '/periods/1/reopen', ['csrf_token' => csrf($pp), 'reopen_reason' => 'Sửa chỉ số', 'confirm_payments' => '1']);
check($G, 'Có xác nhận → mở lại được + thông báo "CẢNH BÁO THANH TOÁN"', $q('SELECT status FROM billing_periods WHERE id = 1') === 'mo_lai'
    && str_contains(http($jar('bql'), 'GET', $baseA . '/periods/1')['body'], 'CẢNH BÁO THANH TOÁN'));

// ---------------------------------------------------------------- Server B: Mock Bank tắt (mặc định production)
$G = 'DISABLED';
$cbB = $callback($baseB, ['transaction_id' => 'CB-OFF', 'amount' => '1', 'content' => 'EDSS000001']);
check($G, 'Server không bật Mock Bank: callback (kể cả chữ ký đúng) → 404, không ghi', $cbB['code'] === 404 && (int) $q("SELECT COUNT(*) FROM payment_transactions WHERE transaction_id = 'CB-OFF'") === 0);
check($G, 'Mock Bank tắt: GET /mock-bank → 404 cho Kế toán đã đăng nhập và cho người chưa đăng nhập (route không tồn tại)', login($baseB, $jar('ktoB'), 'ketoan01')
    && http($jar('ktoB'), 'GET', $baseB . '/mock-bank')['code'] === 404 && http($jar('anonB'), 'GET', $baseB . '/mock-bank')['code'] === 404);
$tokB = csrf(http($jar('ktoB'), 'GET', $baseB . '/dashboard')['body']);
$postB = http($jar('ktoB'), 'POST', $baseB . '/mock-bank/send', ['csrf_token' => $tokB, 'transaction_id' => 'MB-OFF', 'amount' => '1', 'content' => 'EDSS000001']);
check($G, 'Mock Bank tắt: POST /mock-bank/send → 404, không ghi', $postB['code'] === 404 && (int) $q("SELECT COUNT(*) FROM payment_transactions WHERE transaction_id = 'MB-OFF'") === 0);
check($G, 'Mock Bank tắt: menu / dashboard thanh toán vẫn hoạt động, không có Mock Bank', str_contains($pb = http($jar('ktoB'), 'GET', $baseB . '/payments')['body'], 'data-kpi="due"') && !str_contains($pb, 'Mock Bank'));

array_map('unlink', glob("{$tmp}/*") ?: []);
@rmdir($tmp);
echo "\n{$pass}/" . ($pass + $fail) . ($fail === 0 ? ' PASS' : ' - CÓ LỖI') . " (payment HTTP qua {$baseA} + {$baseB}, db={$db})\n";
exit($fail === 0 ? 0 : 1);
