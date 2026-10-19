<?php

declare(strict_types=1);

/**
 * UI chi tiết kỳ ghi chỉ số (chỉ hiển thị - không đổi nghiệp vụ). Chạy qua tests/run_period_detail_ui_tests.sh (DB tạm + php -S).
 *   Fixture (chỉ trên DB tạm): thêm 5 công tơ + 5 chỉ số ĐÃ XÁC NHẬN cho kỳ 06/2024 (đã khoá) -> 13 công tơ đang hoạt động, 0 chờ ghi số
 *   (đúng tình huống "13 công tơ nhưng không thấy danh sách"); kỳ 07/2024 (mở): 1 chỉ số Nháp, còn lại Chưa nhập.
 *   1) Kỳ khoá: thẻ KPI = số dòng danh sách = 13; 5 dòng đầu hiện, 8 dòng ẩn; nút "Xem tất cả 13 công tơ"; 13 badge "Đã xác nhận";
 *      dòng "Tất cả 13 công tơ đều đã có chỉ số…"; chờ ghi số = empty state; "Tính phí (FN-05)" không khả dụng + tooltip; vẫn xem được bản tính.
 *   2) Kỳ mở: badge "Nháp" (1) + "Chưa nhập" (12); không có dòng "Tất cả … xác nhận"; "Tính phí (FN-05)" là link; chờ ghi số 12 dòng.
 *   3) Chrome headless: bấm "Xem tất cả" -> 13 dòng hiện, "Hiển thị 13/13", nút "Thu gọn"; bấm lại -> về 5 dòng. Ảnh chụp desktop / mobile (tuỳ chọn).
 *   php tests/period_detail_ui_test.php <base_url> <db_tạm> [thư_mục_ảnh]
 */

$base = rtrim($argv[1] ?? '', '/');
$db = (string) ($argv[2] ?? '');
$shotDir = (string) ($argv[3] ?? '');
if ($base === '' || $db === '' || in_array($db, ['energy_dss', 'energy_dss_v2'], true)) {
    fwrite(STDERR, "Dùng: php tests/period_detail_ui_test.php <base_url> <db_tạm> [thư_mục_ảnh]\n");
    exit(2);
}
$pdo = new PDO("mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0;
$fail = 0;
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . str_pad($g, 8) . " {$n}" . ($ok || $d === '' ? '' : "  -> {$d}") . "\n";
}
function http(string $jar, string $method, string $url, array $post = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $r = ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'location' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL), 'body' => $body];
    curl_close($ch);

    return $r;
}
function login(string $base, string $jar, string $user): bool
{
    @unlink($jar);
    $page = http($jar, 'GET', $base . '/login');
    preg_match('/name="csrf_token" value="([^"]+)"/', $page['body'], $m);

    return str_ends_with(http($jar, 'POST', $base . '/login', ['csrf_token' => $m[1] ?? '', 'username' => $user, 'password' => 'Password@123'])['location'], '/dashboard');
}
function count_re(string $re, string $s): int
{
    return (int) preg_match_all($re, $s);
}
/** Khối panel chứa tiêu đề $title (đến panel kế tiếp). */
function section(string $html, string $title): string
{
    $p = strpos($html, $title);
    if ($p === false) {
        return '';
    }
    $end = strpos($html, '<div class="panel', $p);

    return substr($html, $p, $end === false ? null : $end - $p);
}

// ---------------------------------------------------------------- fixture (DB tạm)
$locked = (int) $pdo->query("SELECT id FROM billing_periods WHERE period_code = '06/2024'")->fetchColumn();
$open = (int) $pdo->query("SELECT id FROM billing_periods WHERE period_code = '07/2024'")->fetchColumn();
$ins = $pdo->prepare("INSERT INTO meters (apartment_id, meter_code, utility_type, usage_type, initial_reading, status) VALUES (?, ?, ?, 'sinh_hoat', 0, 'hoat_dong')");
$rd = $pdo->prepare("INSERT INTO meter_readings (meter_id, billing_period_id, previous_reading, current_reading, consumption, status, source, note, entered_by, confirmed_by, confirmed_at)
    VALUES (?, ?, 0, 10, 10, 'da_xac_nhan', 'thu_cong', ?, 2, 1, NOW())");
foreach ([[1, 'UI-T01', 'dien'], [2, 'UI-T02', 'nuoc'], [3, 'UI-T03', 'dien'], [4, 'UI-T04', 'nuoc'], [1, 'UI-T05', 'dien']] as $i => [$apt, $code, $u]) {
    $ins->execute([$apt, $code, $u]);
    $rd->execute([(int) $pdo->lastInsertId(), $locked, $i === 0 ? 'Đọc lại tại hiện trường' : null]);
}
$bt1 = (int) $pdo->query("SELECT id FROM meters WHERE meter_code = 'BT1-DIEN'")->fetchColumn();
$pdo->prepare("INSERT INTO meter_readings (meter_id, billing_period_id, previous_reading, current_reading, consumption, status, source, entered_by) VALUES (?, ?, 1343, 1800, 457, 'nhap', 'thu_cong', 2)")->execute([$bt1, $open]);
$active = (int) $pdo->query("SELECT COUNT(*) FROM meters m JOIN apartments a ON a.id = m.apartment_id WHERE m.status = 'hoat_dong' AND a.status = 'hoat_dong'")->fetchColumn();
check('SETUP', "Fixture: {$active} công tơ đang hoạt động (cần 13)", $active === 13);

$jar = sys_get_temp_dir() . '/period_ui_' . getmypid() . '.jar';
check('SETUP', 'Đăng nhập bql01', login($base, $jar, 'bql01'));

// ---------------------------------------------------------------- 1) kỳ đã khoá: 13 công tơ, 0 chờ ghi số
$r = http($jar, 'GET', "{$base}/periods/{$locked}");
$h = $r['body'];
$G = 'KHOÁ';
check($G, 'Trang kỳ 06/2024 (đã khoá) 200, không lỗi PHP', $r['code'] === 200 && !preg_match('/Fatal error|Warning:|Notice:|Uncaught|SQLSTATE/', $h));
check($G, 'KPI: 13 đang hoạt động / 0 chờ ghi số / 0 nháp / 13 đã xác nhận',
    (bool) preg_match('#Công tơ đang hoạt động</div><div class="stat-card__value">13<#u', $h) && (bool) preg_match('#Chờ ghi số</div><div class="stat-card__value">0<#u', $h)
    && (bool) preg_match('#Đã nhập — Nháp</div><div class="stat-card__value">0<#u', $h) && (bool) preg_match('#Đã xác nhận</div><div class="stat-card__value">13<#u', $h));
$list = section($h, 'Danh sách công tơ đang hoạt động');
check($G, 'Có khối "Danh sách công tơ đang hoạt động (13)" ngay sau KPI', str_contains($h, 'Danh sách công tơ đang hoạt động (13)')
    && strpos($h, 'Danh sách công tơ đang hoạt động') > strpos($h, 'stat-card__value') && strpos($h, 'Danh sách công tơ đang hoạt động') < strpos($h, 'Công tơ chờ ghi số'));
check($G, 'Danh sách đủ 13 dòng (= KPI), cột STT / Mã công tơ / Căn hộ / Loại / Trạng thái chỉ số / Ghi chú',
    count_re('/<tr data-meter-row/', $list) === 13 && str_contains($list, '<th class="num">STT</th><th>Mã công tơ</th><th>Căn hộ</th><th>Loại</th><th>Trạng thái chỉ số</th><th>Ghi chú</th>'));
check($G, 'Mặc định 5 dòng đầu hiện, 8 dòng sau ẩn', count_re('/<tr data-meter-row>/', $list) === 5 && count_re('/<tr data-meter-row hidden>/', $list) === 8);
check($G, 'Nút "Xem tất cả 13 công tơ" (link dự phòng tới Tra cứu chỉ số kỳ)', (bool) preg_match('#<a [^>]*href="[^"]*/readings\?period_id=' . $locked . '"[^>]*data-meter-list-toggle[^>]*>Xem tất cả 13 công tơ</a>#u', $list));
check($G, '13 badge "Đã xác nhận" màu xanh, không có Nháp / Chưa nhập', count_re('#<span class="badge badge--active">Đã xác nhận</span>#u', $list) === 13 && !str_contains($list, '>Nháp<') && !str_contains($list, '>Chưa nhập<'));
check($G, 'Loại hiển thị Điện / Nước; Ghi chú lấy từ chỉ số (còn lại "—")', str_contains($list, '<td>Điện</td>') && str_contains($list, '<td>Nước</td>') && str_contains($list, 'Đọc lại tại hiện trường') && str_contains($list, '<td>—</td>'));
check($G, 'Dòng tóm tắt "Hiển thị 5/13" + "Tất cả 13 công tơ đều đã có chỉ số và được xác nhận trong kỳ này."',
    (bool) preg_match('#Hiển thị <span data-meter-list-shown>5</span>/13 công tơ\.#u', $list) && str_contains($list, 'Tất cả 13 công tơ đều đã có chỉ số và được xác nhận trong kỳ này.'));
$pend = section($h, 'Công tơ chờ ghi số');
check($G, 'Chờ ghi số (0): empty state đúng nội dung', str_contains($h, 'Công tơ chờ ghi số (0)') && str_contains($pend, 'Không có công tơ chờ ghi số.')
    && str_contains($pend, 'Tất cả công tơ đang hoạt động đều đã có chỉ số trong kỳ 06/2024.') && !str_contains($pend, '<table'));
check($G, 'Lô import (0): "Chưa có lô import."', str_contains($h, 'Lô import (0)') && str_contains(section($h, 'Lô import'), 'Chưa có lô import.'));
check($G, '"Tính phí (FN-05)" không khả dụng + tooltip "Kỳ đã khóa, cần mở lại kỳ trước khi tính lại phí", không link tính phí ở header',
    (bool) preg_match('#<span class="btn btn--ghost" role="link" aria-disabled="true"[^>]*title="Kỳ đã khóa, cần mở lại kỳ trước khi tính lại phí"[^>]*>Tính phí \(FN-05\)</span>#u', $h)
    && !preg_match('#<a [^>]*href="[^"]*/periods/' . $locked . '/billing"[^>]*>Tính phí \(FN-05\)</a>#u', $h));
check($G, 'Vẫn xem được bản tính kỳ khoá: "Xem bản tính của kỳ" trong khối Tính phí & chốt kỳ', (bool) preg_match('#href="[^"]*/periods/' . $locked . '/billing"[^>]*>Xem bản tính của kỳ</a>#u', $h));
check($G, 'Giữ nút header: Tra cứu chỉ số kỳ, Gửi Gmail cho các hộ, Hộp thư theo kỳ, Mở lại kỳ (+ form / modal giữ nguyên)',
    str_contains($h, '>Tra cứu chỉ số kỳ</a>') && str_contains($h, 'Gửi Gmail cho các hộ</button>') && str_contains($h, 'data-bill-email-send') && str_contains($h, '>Hộp thư theo kỳ</a>')
    && str_contains($h, 'data-open-modal="reopenModal">Mở lại kỳ</button>') && str_contains($h, 'id="reopenModal"'));
check($G, 'Billing kỳ khoá vẫn mở được (không đổi quyền / nghiệp vụ)', http($jar, 'GET', "{$base}/periods/{$locked}/billing")['code'] === 200);

// ---------------------------------------------------------------- 2) kỳ mở: Nháp / Chưa nhập
$h2 = http($jar, 'GET', "{$base}/periods/{$open}")['body'];
$list2 = section($h2, 'Danh sách công tơ đang hoạt động');
$G = 'MỞ';
check($G, 'Kỳ 07/2024: danh sách 13 dòng = KPI 13', str_contains($h2, 'Danh sách công tơ đang hoạt động (13)') && count_re('/<tr data-meter-row/', $list2) === 13);
check($G, 'Badge: 1 "Nháp" (xám), 12 "Chưa nhập" (cam), 0 "Đã xác nhận"', count_re('#<span class="badge badge--muted">Nháp</span>#u', $list2) === 1
    && count_re('#<span class="badge badge--warning">Chưa nhập</span>#u', $list2) === 12 && !str_contains($list2, '>Đã xác nhận<'));
check($G, 'Không có dòng "Tất cả … được xác nhận" khi chưa xác nhận hết', !str_contains($list2, 'đều đã có chỉ số và được xác nhận'));
check($G, '"Tính phí (FN-05)" là link bình thường (kỳ chưa khoá)', (bool) preg_match('#<a class="btn btn--ghost" href="[^"]*/periods/' . $open . '/billing">Tính phí \(FN-05\)</a>#u', $h2) && !str_contains($h2, 'data-billing-disabled'));
check($G, 'Chờ ghi số (12): bảng 12 dòng, không empty state', str_contains($h2, 'Công tơ chờ ghi số (12)') && count_re('#<tr>\s*<td>#', section($h2, 'Công tơ chờ ghi số')) === 12);

// ---------------------------------------------------------------- 3) Chrome: "Xem tất cả" không tải lại trang
$chrome = null;
foreach ([(string) getenv('CHROME_BIN'), '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium'] as $c) {
    if ($c !== '' && is_file($c)) {
        $chrome = $c;
        break;
    }
}
if ($chrome === null) {
    check('CHROME', 'Có Chrome để kiểm tra nút "Xem tất cả"', false, 'không tìm thấy Chrome (đặt CHROME_BIN)');
} else {
    $tmp = sys_get_temp_dir() . '/period_ui_' . getmypid();
    @mkdir($tmp);
    // trang đã đăng nhập (HTML server) + script tự bấm nút 2 lần, ghi kết quả vào DOM; CSS / JS lấy từ server test
    $probe = '<script>window.addEventListener("load",function(){var b=document.querySelector("[data-meter-list-toggle]"),p=b.closest("[data-meter-list]");'
        . 'function st(){var r=p.querySelectorAll("[data-meter-row]"),h=0;for(var i=0;i<r.length;i++){if(r[i].hidden){h++;}}return h+"|"+p.querySelector("[data-meter-list-shown]").textContent+"|"+b.textContent+"|"+b.getAttribute("aria-expanded");}'
        . 'var s0=st();b.click();var s1=st();b.click();var s2=st();var o=document.createElement("pre");o.id="probe";o.textContent=s0+"#"+s1+"#"+s2+"#"+location.href;document.body.appendChild(o);});</script>';
    file_put_contents("{$tmp}/page.html", str_replace('</body>', $probe . '</body>', $h));
    $dom = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-first-run --virtual-time-budget=5000 --dump-dom ' . escapeshellarg("file://{$tmp}/page.html") . ' 2>/dev/null');
    preg_match('#<pre id="probe">([^<]*)</pre>#', $dom, $pm);
    [$s0, $s1, $s2, $href] = array_pad(explode('#', html_entity_decode($pm[1] ?? '')), 4, '');
    check('CHROME', 'Ban đầu: 8 dòng ẩn, "Hiển thị 5", nút "Xem tất cả 13 công tơ"', $s0 === '8|5|Xem tất cả 13 công tơ|false', $s0);
    check('CHROME', 'Bấm "Xem tất cả": 0 dòng ẩn, "Hiển thị 13", nút "Thu gọn"', $s1 === '0|13|Thu gọn|true', $s1);
    check('CHROME', 'Bấm "Thu gọn": về 5 dòng', $s2 === '8|5|Xem tất cả 13 công tơ|false', $s2);
    check('CHROME', 'Không điều hướng / tải lại trang khi bấm', str_starts_with($href, 'file://'), $href);
    // Responsive: cửa sổ hẹp nhất Chrome headless cho phép (~500px) - trang không tràn ngang, thẻ KPI xuống dòng
    $narrow = '<script>window.addEventListener("load",function(){var g=document.querySelector(".card-grid"),c=getComputedStyle(g).gridTemplateColumns.split(" ").length;'
        . 'var o=document.createElement("pre");o.id="narrow";o.textContent=document.documentElement.scrollWidth+"|"+window.innerWidth+"|"+c;document.body.appendChild(o);});</script>';
    file_put_contents("{$tmp}/narrow.html", str_replace('</body>', $narrow . '</body>', $h));
    $dom = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-first-run --window-size=400,900 --virtual-time-budget=5000 --dump-dom ' . escapeshellarg("file://{$tmp}/narrow.html") . ' 2>/dev/null');
    preg_match('#<pre id="narrow">(\d+)\|(\d+)\|(\d+)</pre>#', $dom, $nm);
    check('CHROME', 'Màn hẹp (' . ($nm[2] ?? '?') . 'px): không tràn ngang, KPI ≤ 2 cột', isset($nm[1]) && (int) $nm[1] <= (int) $nm[2] && (int) $nm[3] <= 2,
        'scrollWidth=' . ($nm[1] ?? '?') . ' innerWidth=' . ($nm[2] ?? '?') . ' cột=' . ($nm[3] ?? '?'));
    if ($shotDir !== '') {
        @mkdir($shotDir, 0777, true);
        file_put_contents("{$tmp}/shot.html", $h);
        foreach (['desktop' => '1440,1500', 'mobile' => '500,2400'] as $name => $size) {
            shell_exec(escapeshellarg($chrome) . " --headless=new --disable-gpu --no-first-run --hide-scrollbars --virtual-time-budget=3000 --window-size={$size} --screenshot="
                . escapeshellarg("{$shotDir}/period_{$name}.png") . ' ' . escapeshellarg("file://{$tmp}/shot.html") . ' 2>/dev/null');
        }
        echo "ảnh: {$shotDir}/period_desktop.png, period_mobile.png\n";
    }
    array_map('unlink', glob("{$tmp}/*") ?: []);
    @rmdir($tmp);
}
@unlink($jar);

echo "\n{$pass}/" . ($pass + $fail) . ($fail === 0 ? ' PASS' : ' - CÓ LỖI') . " (UI chi tiết kỳ qua {$base}, db={$db})\n";
exit($fail === 0 ? 0 : 1);
