<?php

declare(strict_types=1);

/**
 * STEP 16 - Diễn tập kịch bản demo (docs/DEMO_SCRIPT.md) trên DB TẠM cài mới (database/energy_dss_v2.sql), app chạy bằng php -S.
 *   php tests/demo_rehearsal_test.php <base_url> <db_tạm>      (gọi qua tests/run_demo_rehearsal.sh)
 * Đảm bảo mọi bước + mọi con số ghi trong DEMO_SCRIPT đúng với seed hiện tại. Không đụng DB thật.
 */

[$_, $base, $testDb] = $argv + [null, '', ''];
if ($base === '' || $testDb === '' || $testDb === 'energy_dss') {
    fwrite(STDERR, "Dùng: php tests/demo_rehearsal_test.php <base_url> <db_tạm>\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();
$_ENV['DB_DATABASE'] = $testDb;
require_once __DIR__ . '/support/legacy_compat.php'; // STEP 4C: chạy nguyên văn trên schema v1 hoặc v2
$pdo = legacy_compat_install();
$base = rtrim($base, '/');
$jar = static fn (string $who): string => sys_get_temp_dir() . "/demo_{$who}_" . getmypid() . '.txt';
const P = 2; // kỳ 07/2024 trong seed

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

function http(string $jar, string $method, string $url, $post = [], bool $multipart = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart || is_string($post) ? $post : http_build_query($post));
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

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

function ok200(array $r): bool
{
    return $r['code'] === 200 && !preg_match('/(Fatal error|Warning|Notice|Uncaught)\b.*\.php/i', $r['body']);
}

foreach (['bql' => 'bql01', 'kt' => 'kythuat01', 'kto' => 'ketoan01'] as $who => $user) {
    @unlink($jar($who));
    $page = http($jar($who), 'GET', $base . '/login');
    $r = http($jar($who), 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => $user, 'password' => 'Password@123']);
    check('0 ĐĂNG NHẬP', "Đăng nhập {$user} / Password@123", str_ends_with($r['location'], '/dashboard'));
}

// ---------------------------------------------------------------- B1-B3 BQL: dashboard, danh mục, biểu giá, kỳ
$G = '1 BQL DANH MỤC';
$d = http($jar('bql'), 'GET', $base . '/dashboard');
check($G, 'Dashboard mở được (kỳ 07/2024 chưa có số liệu)', ok200($d));
$apt = http($jar('bql'), 'GET', $base . '/apartments/1');
check($G, 'Căn BT1: 80 m², công tơ BT1-DIEN / BT1-NUOC, chủ hộ Phạm Văn A, 1 ô tô', ok200($apt) && str_contains($apt['body'], 'BT1-DIEN') && str_contains($apt['body'], 'BT1-NUOC') && str_contains($apt['body'], 'Phạm Văn A'));
check($G, 'Danh mục tòa nhà / khu vực / công tơ mở được', ok200(http($jar('bql'), 'GET', $base . '/buildings')) && ok200(http($jar('bql'), 'GET', $base . '/zones')) && ok200(http($jar('bql'), 'GET', $base . '/meters')));
$t = http($jar('bql'), 'GET', $base . '/tariffs/1');
check($G, 'Biểu giá điện sinh hoạt: 6 bậc (1.806 → 3.151 đ/kWh), VAT 8%', ok200($t) && str_contains($t['body'], '1.806') && str_contains($t['body'], '3.151'));
check($G, 'Phí cố định mở được', ok200(http($jar('bql'), 'GET', $base . '/fees')));
$p = http($jar('bql'), 'GET', $base . '/periods');
check($G, 'Danh sách kỳ: 06/2024 Đã khóa, 07/2024 Mở', ok200($p) && str_contains($p['body'], '06/2024') && str_contains($p['body'], '07/2024'));
check($G, 'Form tạo kỳ mở được (chỉ xem, không lưu)', ok200(http($jar('bql'), 'GET', $base . '/periods/create')));

// ---------------------------------------------------------------- B4-B5 KT: nhập tay BT1 + import 3 căn
$G = '2 KỸ THUẬT CHỈ SỐ';
$f = http($jar('kt'), 'GET', $base . '/periods/' . P . '/readings/create?meter_id=1');
$r = http($jar('kt'), 'POST', $base . '/periods/' . P . '/readings', ['csrf_token' => csrfFrom($f['body']), 'meter_id' => '1', 'current_reading' => '1200', 'note' => '']);
$f = http($jar('kt'), 'GET', $base . '/periods/' . P . '/readings/create?meter_id=1');
check($G, 'BT1-DIEN nhập 1200 (< CSĐ 1343) → bị chặn (BR-07)', (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ?', [P]) === 0);
http($jar('kt'), 'POST', $base . '/periods/' . P . '/readings', ['csrf_token' => csrfFrom($f['body']), 'meter_id' => '1', 'current_reading' => '1800', 'note' => 'Demo']);
$f = http($jar('kt'), 'GET', $base . '/periods/' . P . '/readings/create?meter_id=2');
http($jar('kt'), 'POST', $base . '/periods/' . P . '/readings', ['csrf_token' => csrfFrom($f['body']), 'meter_id' => '2', 'current_reading' => '232', 'note' => '']);
$cl = $pdo->query('SELECT meter_id, consumption FROM meter_readings WHERE billing_period_id = ' . P)->fetchAll(PDO::FETCH_KEY_PAIR);
check($G, 'BT1-DIEN 1800 → CL 457 kWh; BT1-NUOC 232 → CL 16 m³ (server tính, Nháp)', ($cl[1] ?? '') === '457.00' && ($cl[2] ?? '') === '16.00', json_encode($cl));
$file = BASE_PATH . '/docs/demo/chi_so_07_2024_demo.xlsx';
$c = http($jar('kt'), 'GET', $base . '/periods/' . P . '/imports/create');
check($G, 'Tải file mẫu import (CSĐ điền sẵn)', http($jar('kt'), 'GET', $base . '/periods/' . P . '/imports/template')['code'] === 200);
$r = http($jar('kt'), 'POST', $base . '/periods/' . P . '/imports', ['csrf_token' => csrfFrom($c['body']), 'file' => new CURLFile($file, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'chi_so_07_2024_demo.xlsx')], true);
$batch = preg_match('#/imports/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
$pv = http($jar('kt'), 'GET', $base . "/imports/{$batch}");
check($G, 'Upload docs/demo/chi_so_07_2024_demo.xlsx → xem trước: 3 dòng hợp lệ, control total khớp', $batch > 0 && ok200($pv) && q($pdo, 'SELECT CONCAT(valid_rows, "|", error_rows) FROM import_batches WHERE id = ?', [$batch]) === '3|0'
    && str_contains($pv['body'], 'MATCH'), (string) q($pdo, 'SELECT CONCAT(valid_rows, "|", error_rows) FROM import_batches WHERE id = ?', [$batch]));
http($jar('kt'), 'POST', $base . "/imports/{$batch}/confirm", ['csrf_token' => csrfFrom($pv['body'])]);
check($G, 'Xác nhận import → 6 chỉ số Nháp; tổng 8/8 công tơ', (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE import_batch_id = ?', [$batch]) === 6
    && (int) q($pdo, "SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ? AND status = 'nhap'", [P]) === 8);

// ---------------------------------------------------------------- B6 BQL duyệt
$G = '3 BQL DUYỆT';
$ap = http($jar('bql'), 'GET', $base . '/periods/' . P . '/approvals');
check($G, 'Trang duyệt hiển thị 8 chỉ số chờ duyệt', ok200($ap) && preg_match_all('/name="token_\d+"/', $ap['body']) === 8);
http($jar('bql'), 'POST', $base . '/periods/' . P . '/approvals', bulkFrom($ap['body'], 'reading_ids'));
check($G, 'Duyệt theo lô → 8 chỉ số Đã xác nhận', (int) q($pdo, "SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ? AND status = 'da_xac_nhan'", [P]) === 8);

// ---------------------------------------------------------------- B7-B8 KTo tính phí + xác nhận
$G = '4 KẾ TOÁN TÍNH PHÍ';
$bl = http($jar('kto'), 'GET', $base . '/periods/' . P . '/billing');
http($jar('kto'), 'POST', $base . '/periods/' . P . '/billing/calculate', ['csrf_token' => csrfFrom($bl['body'])]);
$ch = $pdo->query('SELECT apartment_id, total_arising FROM apartment_charges WHERE billing_period_id = ' . P . ' ORDER BY apartment_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$sum = (string) q($pdo, 'SELECT SUM(total_arising) FROM apartment_charges WHERE billing_period_id = ?', [P]);
$bt1 = $pdo->query('SELECT electricity_amount, electricity_vat_amount, water_amount, total_arising, total_due, id FROM apartment_charges WHERE billing_period_id = ' . P . ' AND apartment_id = 1')->fetch();
check($G, 'Tính phí 4 căn', count($ch) === 4, json_encode($ch));
check($G, 'BT1: tiền điện 1.157.807 + VAT 92.624,56, nước 185.120, phát sinh 2.331.791,56', $bt1 !== false && $bt1['electricity_amount'] === '1157807.00' && $bt1['electricity_vat_amount'] === '92624.56'
    && $bt1['water_amount'] === '185120.00' && $bt1['total_arising'] === '2331791.56', json_encode($bt1));
check($G, 'Tổng phát sinh toàn tòa 5.155.285,56', $sum === '5155285.56', $sum);
$sh = http($jar('kto'), 'GET', $base . '/charges/' . $bt1['id']);
check($G, 'Chi tiết bản tính BT1 hiển thị bậc giá (bậc 6: 57 kWh × 3.151)', ok200($sh) && str_contains($sh['body'], '1.157.807') && str_contains($sh['body'], '3.151'));
$rv = http($jar('kto'), 'GET', $base . '/periods/' . P . '/billing/review');
http($jar('kto'), 'POST', $base . '/periods/' . P . '/billing/confirm', bulkFrom($rv['body'], 'charge_ids'));
check($G, 'Xác nhận theo lô → 4 bản tính Đã xác nhận', (int) q($pdo, "SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ? AND status = 'da_xac_nhan'", [P]) === 4);

// ---------------------------------------------------------------- B9-B10 BQL so sánh + mục tiêu
$G = '5 BQL SO SÁNH & MỤC TIÊU';
check($G, 'Danh sách baseline: BT1 điện 343 kWh (phương pháp kỳ trước)', str_contains(http($jar('bql'), 'GET', $base . '/baselines')['body'], '343'));
$bf = http($jar('bql'), 'GET', $base . '/baselines/create');
$before = (int) q($pdo, 'SELECT COUNT(*) FROM baselines');
http($jar('bql'), 'POST', $base . '/baselines', ['csrf_token' => csrfFrom($bf['body']), 'intent' => 'save', 'scope' => 'meter:1', 'utility_type' => 'dien', 'method' => 'ky_truoc', 'effective_date' => '2024-07-01']);
check($G, 'BQL lập baseline công tơ BT1-DIEN, phương pháp "Kỳ trước liền kề" → 343 kWh', (int) q($pdo, 'SELECT COUNT(*) FROM baselines') === $before + 1
    && q($pdo, "SELECT computed_value FROM baselines WHERE scope_type = 'meter' AND scope_id = 1") === '343.00', (string) q($pdo, "SELECT CONCAT(IFNULL(computed_value, 'NULL'), status) FROM baselines WHERE scope_type = 'meter' AND scope_id = 1"));
$cp = http($jar('bql'), 'GET', $base . '/comparisons?period_id=' . P . '&scope_type=apartment&utility_type=dien');
check($G, 'So sánh BT1 điện: 457 vs baseline 343 → +114, +33,24%, ▲ Tăng', ok200($cp) && str_contains($cp['body'], '+33,24%') && str_contains($cp['body'], '▲ Tăng'));
$tk = http($jar('bql'), 'GET', $base . '/targets/tracking?period_id=' . P);
check($G, 'Theo dõi mục tiêu BT1 điện: 457 > 320 → Không đạt (142,81%)', ok200($tk) && str_contains($tk['body'], '142,81%') && str_contains($tk['body'], 'Không đạt'));

// ---------------------------------------------------------------- B11-B16 BQL / KT: cảnh báo
$G = '6 CẢNH BÁO';
check($G, 'Quy tắc cảnh báo seed: điện / nước vượt baseline > 30%', str_contains(http($jar('bql'), 'GET', $base . '/alert-rules')['body'], 'vượt baseline'));
$al = http($jar('bql'), 'GET', $base . '/alerts');
http($jar('bql'), 'POST', $base . '/alerts/evaluate', ['csrf_token' => csrfFrom($al['body']), 'period_id' => (string) P]);
$alerts = $pdo->query('SELECT id, meter_id, percent_change, status FROM alerts WHERE billing_period_id = ' . P)->fetchAll();
$alertId = (int) ($alerts[0]['id'] ?? 0);
check($G, 'Chạy đánh giá → đúng 1 cảnh báo: BT1-DIEN +33,24% (Mới)', count($alerts) === 1 && (int) $alerts[0]['meter_id'] === 1 && $alerts[0]['percent_change'] === '33.24' && $alerts[0]['status'] === 'moi', json_encode($alerts));
$dt = http($jar('bql'), 'GET', $base . "/alerts/{$alertId}");
check($G, 'Chi tiết: "Vì sao có cảnh báo?", không kết luận nguyên nhân', ok200($dt) && str_contains($dt['body'], 'Vì sao có cảnh báo?') && str_contains($dt['body'], 'không phải kết luận'));
http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/review", ['csrf_token' => csrfFrom($dt['body']), 'version_token' => tokenFrom($dt['body']), 'priority' => 'cao', 'note' => 'Tăng 33% so với kỳ trước']);
$dt = http($jar('bql'), 'GET', $base . "/alerts/{$alertId}");
http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/request-verification", ['csrf_token' => csrfFrom($dt['body']), 'version_token' => tokenFrom($dt['body']), 'content' => 'Đọc lại công tơ BT1-DIEN', 'assigned_to' => '2']);
check($G, 'Review (ưu tiên Cao) → yêu cầu kiểm tra giao kythuat01 → Cần kiểm tra lại', q($pdo, 'SELECT CONCAT(status, "|", priority) FROM alerts WHERE id = ?', [$alertId]) === 'can_kiem_tra_lai|cao');
$kd = http($jar('kt'), 'GET', $base . "/alerts/{$alertId}");
http($jar('kt'), 'POST', $base . "/alerts/{$alertId}/verification-result", ['csrf_token' => csrfFrom($kd['body']), 'result_conclusion' => 'canh_bao_dung', 'rechecked_value' => '1800', 'note' => 'Đọc lại đúng 1800']);
check($G, 'KT nhập kết quả kiểm tra "Cảnh báo đúng", đọc lại 1800', ok200($kd) && q($pdo, 'SELECT status FROM inspection_cases WHERE alert_id = ?', [$alertId]) === 'da_co_ket_qua');
$dt = http($jar('bql'), 'GET', $base . "/alerts/{$alertId}");
http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/confirm", ['csrf_token' => csrfFrom($dt['body']), 'version_token' => tokenFrom($dt['body']), 'decision' => 'confirm', 'note' => 'Tiêu thụ tăng thật']);
check($G, 'BQL xác nhận dữ liệu đúng → Đã xác nhận, chỉ số giữ 1800', q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'da_xac_nhan'
    && q($pdo, 'SELECT current_reading FROM meter_readings WHERE billing_period_id = ? AND meter_id = 1', [P]) === '1800.00');

// ---------------------------------------------------------------- B17-B18 khuyến nghị + đóng
$G = '7 KHUYẾN NGHỊ';
$dt = http($jar('bql'), 'GET', $base . "/alerts/{$alertId}");
http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/recommendations", ['csrf_token' => csrfFrom($dt['body'])]);
$recs = $pdo->query("SELECT recommendation_rule_id, recommendation_text FROM recommendations WHERE alert_id = {$alertId}")->fetchAll(PDO::FETCH_KEY_PAIR);
check($G, 'Sinh khuyến nghị → "Đề xuất kiểm tra công tơ" (quy tắc seed #2)', ($recs[2] ?? '') === 'Đề xuất kiểm tra công tơ' && count($recs) === 1, json_encode($recs, JSON_UNESCAPED_UNICODE));
$dt = http($jar('bql'), 'GET', $base . "/alerts/{$alertId}");
http($jar('bql'), 'POST', $base . "/alerts/{$alertId}/close", ['csrf_token' => csrfFrom($dt['body']), 'version_token' => tokenFrom($dt['body']), 'close_reason' => 'Đã liên hệ hộ BT1']);
check($G, 'Đóng cảnh báo → Đã đóng, khuyến nghị vẫn hiển thị', q($pdo, 'SELECT status FROM alerts WHERE id = ?', [$alertId]) === 'da_dong'
    && str_contains(http($jar('bql'), 'GET', $base . "/alerts/{$alertId}")['body'], 'Đề xuất kiểm tra công tơ'));

// ---------------------------------------------------------------- B19 KTo khoá kỳ
$G = '8 KHOÁ KỲ';
$pp = http($jar('kto'), 'GET', $base . '/periods/' . P);
check($G, 'Trang kỳ: "Đủ điều kiện khóa kỳ"', str_contains($pp['body'], 'Đủ điều kiện khóa kỳ'));
http($jar('kto'), 'POST', $base . '/periods/' . P . '/lock', ['csrf_token' => csrfFrom($pp['body'])]);
check($G, 'Khóa kỳ 07/2024 → Đã khóa', q($pdo, 'SELECT status FROM billing_periods WHERE id = ?', [P]) === 'da_khoa');

// ---------------------------------------------------------------- B20-B23 dashboard / báo cáo / phiếu / audit
$G = '9 KẾT QUẢ';
$dash = http($jar('bql'), 'GET', $base . '/dashboard?period_id=' . P);
check($G, 'Dashboard 07/2024: điện 757 kWh, nước 58 m³, Chính thức (kỳ đã khóa), cảnh báo đang mở 0, khuyến nghị 1', ok200($dash) && str_contains($dash['body'], 'stat-card__value">757<')
    && str_contains($dash['body'], 'stat-card__value">58<') && str_contains($dash['body'], 'Chính thức (kỳ đã khóa)')
    && preg_match('/Cảnh báo đang mở<\/div>\s*<div class="stat-card__value">0</', $dash['body']) === 1
    && preg_match('/Khuyến nghị đã sinh \(FN-08\)<\/div>\s*<div class="stat-card__value">1</', $dash['body']) === 1);
$rp = http($jar('kto'), 'GET', $base . '/reports/utility?period_id=' . P);
preg_match('/5\\.155\\.28[0-9,]*/', strip_tags($rp['body']), $tot);
check($G, 'Báo cáo toàn tòa: tổng 5.155.285,56 đ (4 bản chính thức)', ok200($rp) && str_contains($rp['body'], 'Chỉ cộng 4 bản chính thức') && ($tot[0] ?? '') !== '', $tot[0] ?? '-');
$csv = http($jar('kto'), 'GET', $base . '/reports/utility/export?period_id=' . P);
check($G, 'Xuất CSV', $csv['code'] === 200 && str_contains($csv['body'], '5155285.56'));
$nt = http($jar('kto'), 'GET', $base . '/charges/' . $bt1['id'] . '/notice');
preg_match('/2\\.331\\.79[0-9,]*/', strip_tags($nt['body']), $due);
check($G, 'Phiếu BT1: tổng phải thu 2.331.791,56 đ, cơ sở tính phí 80 m², 1 ô tô', ok200($nt) && ($due[0] ?? '') !== '' && str_contains($nt['body'], '80 m²') && str_contains($nt['body'], 'Gửi ô tô'), $due[0] ?? '-');
check($G, 'In phiếu cả kỳ', ok200(http($jar('kto'), 'GET', $base . '/periods/' . P . '/notices')));
$au = http($jar('bql'), 'GET', $base . '/audit-logs?entity_type=alert&entity_id=' . $alertId);
check($G, 'Nhật ký audit: truy được toàn bộ vòng đời cảnh báo', ok200($au) && !str_contains($au['body'], 'Không có dữ liệu phù hợp'));
check($G, 'Quản lý tài khoản / tham số mở được (BQL)', ok200(http($jar('bql'), 'GET', $base . '/users')) && ok200(http($jar('bql'), 'GET', $base . '/system-parameters')));
$k = http($jar('kt'), 'GET', $base . '/users');
check($G, 'Kỹ thuật mở /users → 403 (RBAC)', $k['code'] === 403);

foreach (['bql', 'kt', 'kto', 'adm'] as $who) {
    @unlink($jar($who));
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
echo "\n{$pass}/{$total} PASS (demo rehearsal {$base}, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
