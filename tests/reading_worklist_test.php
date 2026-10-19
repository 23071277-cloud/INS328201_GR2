<?php

declare(strict_types=1);

/**
 * UX FN-04.02 - màn "Nhập chỉ số" dạng worklist (trái: danh sách công tơ + trạng thái; phải: thông tin + nhập CSC).
 *   php tests/reading_worklist_test.php <base_url> <db_tạm>      (gọi qua tests/run_reading_worklist_tests.sh)
 * Seed: kỳ 2 = 07/2024 Mở, 8 công tơ chờ ghi, CSĐ = CSC đã xác nhận 06/2024. Thứ tự worklist: BT1 (1,2), CC-2305 (5,6), CC-2306 (7,8), S01 (3,4).
 * 1) HTTP: trạng thái / thẻ tổng quan / lọc không JS / Lưu nháp / Lưu & nhập tiếp / đã duyệt chỉ đọc / RBAC / CSRF / kỳ khóa.
 * 2) JS trong Chrome headless (trang thật + app.js thật): tìm, lọc, chọn dòng, CL xem trước, CSC < CSĐ chặn submit; responsive 1440 / 900 / 500.
 */

[$_, $base, $testDb] = $argv + [null, '', ''];
if ($base === '' || $testDb === '' || $testDb === 'energy_dss') {
    fwrite(STDERR, "Dùng: php tests/reading_worklist_test.php <base_url> <db_tạm>\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();
$_ENV['DB_DATABASE'] = $testDb;
App\Core\Config::set('database.database', $testDb);
$pdo = App\Core\Database::connection();
$base = rtrim($base, '/');
$jar = static fn (string $who): string => sys_get_temp_dir() . "/wl_{$who}_" . getmypid() . '.txt';
const P = 2;
$entry = $base . '/periods/' . P . '/readings/create';

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

    return str_ends_with($r['location'], '/dashboard');
}

/** @return array<int,array{state:string,hidden:bool,selected:bool}> trạng thái từng dòng worklist theo meter id */
function rows(string $html): array
{
    preg_match_all('/<tr class="wl-row( is-selected)?" data-wl-row="(\d+)" data-state="(\w+)"[^>]*?(\shidden)?>/s', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        $out[(int) $x[2]] = ['state' => $x[3], 'hidden' => ($x[4] ?? '') !== '', 'selected' => $x[1] !== ''];
    }

    return $out;
}

function counts(string $html): array
{
    preg_match_all('/data-wl-count="(\w+)">(\d+)</', $html, $m, PREG_SET_ORDER);

    return array_map('intval', array_column($m, 2, 1));
}

function sectionHidden(string $html, string $name): ?bool
{
    return preg_match('/data-wl-section="' . $name . '"([^>]*)>/', $html, $m) ? str_contains($m[1], 'hidden') : null;
}

function save(string $base, string $jar, string $url, int $meterId, string $csc, string $next, array $extra = []): array
{
    $page = http($jar, 'GET', $url);

    return http($jar, 'POST', $base . '/periods/' . P . '/readings', $extra + ['csrf_token' => csrfFrom($page['body']), 'meter_id' => (string) $meterId, 'current_reading' => $csc, 'note' => '', 'next' => $next]);
}

function findChrome(): ?string
{
    foreach ([(string) getenv('CHROME_BIN'), '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $c) {
        if ($c !== '' && is_file($c) && is_executable($c)) {
            return $c;
        }
    }

    return null;
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

try {
    foreach (['kt' => 'kythuat01', 'bql' => 'bql01', 'kto' => 'ketoan01'] as $who => $user) {
        check('SETUP', "Đăng nhập {$user}", login($base, $jar($who), $user));
    }

    // ================================================================ 1. Worklist ban đầu
    $G = '1 WORKLIST';
    $page = http($jar('kt'), 'GET', $entry);
    $html = $page['body'];
    $r = rows($html);
    check($G, 'Trang 200, không lỗi PHP, KHÔNG còn dropdown chọn công tơ', $page['code'] === 200 && !preg_match('/Fatal error|Warning:|Notice:/', $html) && !str_contains($html, '<select id="meter_id"'));
    check($G, 'Bố cục 2 cột: "Danh sách công tơ cần ghi (8)" + "Thông tin công tơ"', str_contains($html, 'Danh sách công tơ cần ghi (8)') && str_contains($html, 'id="wl-panel-title">Thông tin công tơ'));
    check($G, 'Cột: Căn hộ, Mã công tơ, Tiện ích, Nhãn, CSĐ, Trạng thái', (bool) preg_match('#Căn hộ</th><th scope="col">Mã công tơ</th><th scope="col">Tiện ích</th><th scope="col">Nhãn</th><th scope="col" class="num">CSĐ</th><th scope="col">Trạng thái</th>#', $html));
    check($G, '8 công tơ, thứ tự BT1 → CC-2305 → CC-2306 → S01, đều "Chưa ghi"', array_keys($r) === [1, 2, 5, 6, 7, 8, 3, 4] && array_unique(array_column($r, 'state')) === ['chua_ghi'], json_encode(array_keys($r)));
    check($G, 'Thẻ tổng quan: Tổng 8 / Chưa ghi 8 / Nháp·chờ duyệt 0 / Đã duyệt 0 (+ %)', counts($html) === ['total' => 8, 'chua_ghi' => 8, 'nhap' => 0, 'da_xac_nhan' => 0] && str_contains($html, '100%'), json_encode(counts($html)));
    check($G, 'Trạng thái có chữ (không chỉ màu): badge "Chưa ghi"', substr_count($html, 'badge badge--danger">Chưa ghi</span>') === 8);
    check($G, 'Không có meter_id → tự chọn công tơ Chưa ghi đầu tiên (BT1-DIEN), dòng được đánh dấu + aria-current', ($r[1]['selected'] ?? false) && str_contains($html, 'aria-current="true">BT1-DIEN</a>'));
    check($G, 'Panel: căn hộ, mã, tiện ích, nhãn, loại sử dụng, CSĐ 1.343 kWh (CSC đã xác nhận kỳ 06/2024)', str_contains($html, 'data-wl-field="apartment">CHUNGCU-A / BT1') && str_contains($html, 'data-wl-field="code">BT1-DIEN')
        && str_contains($html, 'data-wl-field="usageLabel">Sinh hoạt') && str_contains($html, 'data-wl-field="csd">1.343</span> <span data-wl-field="unit">kWh') && str_contains($html, 'CSC đã xác nhận kỳ 06/2024'));
    check($G, 'Form: CSĐ chỉ đọc, chỉ nhập CSC, CL xem trước chỉ đọc, ghi chú hiện trường', str_contains($html, 'value="1343" readonly') && str_contains($html, 'name="current_reading"') && (bool) preg_match('/id="wl_cl" value="" readonly data-consumption-preview/', $html)
        && str_contains($html, 'placeholder="Công tơ mờ, không vào được nhà, nghi sai số..."') && !str_contains($html, 'name="consumption"') && !str_contains($html, 'name="previous_reading"'));
    check($G, 'Helper text CSC/CL + aria-describedby', str_contains($html, 'Bạn chỉ cần nhập CSC (chỉ số cuối). Hệ thống tự tính tiêu thụ CL = CSC − CSĐ khi lưu.') && str_contains($html, 'aria-describedby="csc_hint csc_client_error"'));
    check($G, 'Nút: "Lưu & nhập công tơ tiếp theo" (CTA chính, đứng đầu DOM → phím Enter), "Lưu nháp", "Quay về kỳ"', (bool) preg_match('#<div class="wl-actions">\s*<button type="submit" class="btn btn--primary" name="next" value="1">Lưu &amp; nhập công tơ tiếp theo →</button>\s*<button type="submit" class="btn btn--secondary" name="next" value="0">Lưu nháp</button>\s*<a class="btn btn--ghost"[^>]*>Quay về kỳ</a>#', $html));
    check($G, 'Tìm + lọc trạng thái + lọc tiện ích có nhãn (sr-only)', str_contains($html, 'placeholder="Tìm theo căn hộ hoặc mã công tơ..."') && str_contains($html, '<option value="">Tất cả trạng thái</option>')
        && str_contains($html, '<option value="nhap" >Nháp · chờ BQL duyệt</option>') && str_contains($html, '<label class="sr-only" for="wl_q">') && str_contains($html, '<option value="nuoc" >Nước</option>'));

    // ================================================================ 2. Validation giữ nguyên (backend)
    $G = '2 VALIDATION';
    $res = save($base, $jar('kt'), $entry, 1, '1000', '1', ['q' => 'BT1']);
    $back = http($jar('kt'), 'GET', $entry . '?q=BT1&meter_id=1')['body'];
    check($G, 'Case 7: CSC 1000 < CSĐ 1343 → backend từ chối, giữ công tơ + bộ lọc, hiện lỗi + hướng dẫn B5, không lưu', $res['code'] === 302 && str_contains($res['location'], 'q=BT1') && str_contains($res['location'], 'meter_id=1')
        && str_contains($back, 'Chỉ số cuối nhỏ hơn chỉ số đầu') && str_contains($back, 'BLOCKED B5') && str_contains($back, 'aria-invalid="true"') && (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ?', [P]) === 0, $res['location']);
    save($base, $jar('kt'), $entry, 1, 'abc', '0');
    check($G, 'CSC không phải số → backend từ chối', (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ?', [P]) === 0);
    $res = http($jar('kt'), 'POST', $base . '/periods/' . P . '/readings', ['csrf_token' => 'sai', 'meter_id' => '1', 'current_reading' => '1400']);
    check($G, 'CSRF sai → 403, không lưu', $res['code'] === 403 && (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ?', [P]) === 0);

    // ================================================================ 3. Lưu nháp
    $G = '3 LƯU NHÁP';
    $res = save($base, $jar('kt'), $entry, 1, '1400', '0', ['q' => 'BT1', 'status' => 'xyz', 'consumption' => '99999']);
    $saved = $pdo->query('SELECT * FROM meter_readings WHERE meter_id = 1 AND billing_period_id = ' . P)->fetch();
    check($G, 'Case 8: "Lưu nháp" BT1-DIEN CSC 1400 → Nháp, CL 57 do server tính (bỏ qua consumption gửi giả)', $res['code'] === 302 && $saved && $saved['status'] === 'nhap' && $saved['consumption'] === '57.00' && $saved['previous_reading'] === '1343.00');
    check($G, '"Lưu nháp" ở lại công tơ vừa lưu, giữ bộ lọc hợp lệ (bỏ status lạ)', str_ends_with($res['location'], '/periods/2/readings/create?q=BT1&meter_id=1'), $res['location']);
    $html = http($jar('kt'), 'GET', $entry . '?q=BT1&meter_id=1')['body'];
    $r = rows($html);
    check($G, 'Flash: "Đã lưu chỉ số Nháp. Tiêu thụ CL = 57 — chờ BQL duyệt."', str_contains($html, 'Đã lưu chỉ số Nháp. Tiêu thụ CL = 57 — chờ BQL duyệt.'));
    check($G, 'Dòng BT1-DIEN: Chưa ghi → "Nháp · chờ BQL duyệt"; thẻ tổng quan 8 / 7 / 1 / 0', ($r[1]['state'] ?? '') === 'nhap' && counts($html) === ['total' => 8, 'chua_ghi' => 7, 'nhap' => 1, 'da_xac_nhan' => 0], json_encode(counts($html)));
    check($G, 'Panel công tơ đã có Nháp: không còn form nhập (BR-08), hiện CSC 1.400 / CL 57 + link Sửa chỉ số Nháp (luồng FN-04.07 hiện có)', sectionHidden($html, 'entry') === true && sectionHidden($html, 'saved') === false
        && str_contains($html, 'data-wl-field="csc">1.400') && str_contains($html, 'data-wl-field="cl">57') && (bool) preg_match('#href="[^"]*/readings/' . $saved['id'] . '/edit">Sửa chỉ số Nháp#', $html));
    check($G, 'Lọc phía server (không JS) q=BT1 → chỉ 2 dòng BT1 hiển thị', array_keys(array_filter($r, static fn ($x) => !$x['hidden'])) === [1, 2]);

    // ================================================================ 4. Lưu & nhập tiếp
    $G = '4 LƯU & NHẬP TIẾP';
    $res = save($base, $jar('kt'), $entry, 2, '232', '1');
    check($G, 'Case 9: BT1-NUOC "Lưu & nhập tiếp" → chuyển sang công tơ Chưa ghi kế tiếp CC2305-DIEN (id 5)', str_ends_with($res['location'], '/readings/create?meter_id=5'), $res['location']);
    $html = http($jar('kt'), 'GET', $entry . '?meter_id=5')['body'];
    check($G, 'Trang kế tiếp: CC2305-DIEN được chọn, form nhập sẵn sàng, flash CL = 16', (rows($html)[5]['selected'] ?? false) && sectionHidden($html, 'entry') === false && str_contains($html, 'Tiêu thụ CL = 16'));
    $res = save($base, $jar('kt'), $entry, 4, '150', '1');
    check($G, 'Công tơ cuối danh sách (S01-NUOC) → quay vòng về công tơ Chưa ghi đầu tiên (id 5), bỏ qua công tơ đã ghi', str_ends_with($res['location'], '?meter_id=5'), $res['location']);

    // ================================================================ 5. BQL duyệt → chỉ đọc
    $G = '5 ĐÃ DUYỆT';
    $ap = http($jar('bql'), 'GET', $base . '/periods/' . P . '/approvals')['body'];
    preg_match('/name="token_' . $saved['id'] . '" value="([0-9a-f]{40})"/', $ap, $tk);
    http($jar('bql'), 'POST', $base . '/periods/' . P . '/approvals', ['csrf_token' => csrfFrom($ap), 'reading_ids' => [(string) $saved['id']], 'token_' . $saved['id'] => $tk[1] ?? '']);
    $html = http($jar('kt'), 'GET', $entry . '?meter_id=1')['body'];
    check($G, 'BQL duyệt BT1-DIEN → dòng "Đã duyệt", thẻ 8 / 5 / 2 / 1', (rows($html)[1]['state'] ?? '') === 'da_xac_nhan' && counts($html) === ['total' => 8, 'chua_ghi' => 5, 'nhap' => 2, 'da_xac_nhan' => 1], json_encode(counts($html)));
    check($G, 'Case 10: công tơ Đã duyệt → chỉ đọc: "Chỉ số đã được BQL duyệt.", không form nhập, không nút Lưu, ẩn Sửa Nháp', str_contains($html, 'Chỉ số đã được BQL duyệt.') && sectionHidden($html, 'entry') === true
        && (bool) preg_match('#data-wl-link="edit"[^>]*hidden>Sửa chỉ số Nháp#', $html) && str_contains($html, 'badge badge--active" data-wl-field="stateBadge">Đã duyệt'));
    $before = (string) q($pdo, 'SELECT updated_at FROM meter_readings WHERE id = ?', [$saved['id']]);
    save($base, $jar('kt'), $entry, 1, '1500', '0');
    check($G, 'POST thẳng vào công tơ đã duyệt → backend từ chối (BR-08), chỉ số giữ nguyên', (string) q($pdo, 'SELECT current_reading FROM meter_readings WHERE id = ?', [$saved['id']]) === '1400.00'
        && (string) q($pdo, 'SELECT status FROM meter_readings WHERE id = ?', [$saved['id']]) === 'da_xac_nhan' && (string) q($pdo, 'SELECT updated_at FROM meter_readings WHERE id = ?', [$saved['id']]) === $before);
    $html = http($jar('kt'), 'GET', $entry . '?status=chua_ghi')['body'];
    check($G, 'Lọc server status=chua_ghi → chỉ 5 dòng Chưa ghi hiển thị; "Hiển thị 5 / 8"', count(array_filter(rows($html), static fn ($x) => !$x['hidden'])) === 5 && str_contains($html, 'Hiển thị 5 / 8 công tơ'));

    // ================================================================ 6. RBAC + kỳ khóa
    $G = '6 RBAC';
    check($G, 'Case 11: BQL mở màn nhập chỉ số → 403 (không có meter_readings.create)', http($jar('bql'), 'GET', $entry)['code'] === 403);
    check($G, 'Kế toán mở màn nhập chỉ số → 403', http($jar('kto'), 'GET', $entry)['code'] === 403);
    $page = http($jar('kt'), 'GET', $base . '/periods/1/readings/create');
    check($G, 'Kỳ đã khóa (06/2024): xem được worklist (đều Đã duyệt), không có form nhập', $page['code'] === 200 && str_contains($page['body'], 'Kỳ đã khóa') && !str_contains($page['body'], 'data-wl-form')
        && array_unique(array_column(rows($page['body']), 'state')) === ['da_xac_nhan']);
    $guest = sys_get_temp_dir() . '/wl_guest_' . getmypid() . '.txt';
    check($G, 'Chưa đăng nhập → /login', str_ends_with(http($guest, 'GET', $entry)['location'], '/login'));
    @unlink($guest);

    // ================================================================ 7. JS (Chrome headless)
    $G = '7 JS';
    $chrome = findChrome();
    if ($chrome === null) {
        check($G, 'SKIPPED: không tìm thấy Chrome/Chromium (đặt CHROME_BIN)', true);
    } else {
        $harness = <<<'JS'
<script>
window.addEventListener('load', function () {
  var R = {};
  function el(id) { return document.getElementById(id); }
  function visibleIds() { return Array.prototype.filter.call(document.querySelectorAll('[data-wl-row]'), function (r) { return !r.hidden; }).map(function (r) { return +r.getAttribute('data-wl-row'); }); }
  function field(n) { var f = document.querySelector('[data-wl-field="' + n + '"]'); return f ? f.textContent : null; }
  function sec(n) { var s = document.querySelector('[data-wl-section="' + n + '"]'); return s ? !s.hidden : null; }
  function type(f, v) { f.value = v; f.dispatchEvent(new Event('input', { bubbles: true })); }
  function choose(s, v) { s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }
  function row(id) { return document.querySelector('[data-wl-row="' + id + '"]'); }
  var q = el('wl_q'), st = el('wl_status'), ut = el('wl_utility');
  type(q, 'cc2305'); R.search = visibleIds(); R.count = document.querySelector('[data-wl-visible]').textContent;
  type(q, ''); choose(st, 'chua_ghi'); R.chuaGhi = visibleIds();
  choose(ut, 'nuoc'); R.chuaGhiNuoc = visibleIds();
  choose(st, ''); choose(ut, ''); type(q, 'không-có'); R.emptyShown = !document.querySelector('[data-wl-empty]').hidden; type(q, '');
  R.keep = [document.querySelector('[data-wl-keep="status"]').value];
  row(7).click();
  R.select7 = [row(7).classList.contains('is-selected'), row(5).classList.contains('is-selected'), row(7).querySelector('a').getAttribute('aria-current'), field('code'), field('apartment'), field('csd'),
    document.querySelector('[data-wl-input="meter_id"]').value, el('wl_previous').value, sec('entry'), sec('saved'), field('stateBadge')];
  var csc = el('current_reading');
  type(csc, '45,5'); R.preview = [el('wl_cl').value, el('csc_client_error').hidden];
  row(6).click(); R.switchReset = [csc.value, el('wl_previous').value, el('wl_cl').value];
  type(csc, '50'); R.lower = [el('csc_client_error').hidden, el('csc_client_error').textContent, csc.getAttribute('aria-invalid'), el('wl_cl').value];
  // chỉ đo chặn bởi kiểm tra phía trình duyệt: tắt chế độ live (live gửi bằng fetch cũng preventDefault) - live được test riêng (run_live_ux_tests.sh)
  document.querySelector('[data-wl-form]').setAttribute('data-no-live', '');
  var ev = new Event('submit', { bubbles: true, cancelable: true }); document.querySelector('[data-wl-form]').dispatchEvent(ev); R.blocked = ev.defaultPrevented;
  type(csc, '70'); var ev2 = new Event('submit', { bubbles: true, cancelable: true }); document.querySelector('[data-wl-form]').dispatchEvent(ev2); R.allowed = !ev2.defaultPrevented; R.lowerCleared = el('csc_client_error').hidden;
  type(csc, ''); var ev3 = new Event('submit', { bubbles: true, cancelable: true }); document.querySelector('[data-wl-form]').dispatchEvent(ev3); R.emptyBlocked = [ev3.defaultPrevented, el('csc_client_error').textContent];
  row(1).click(); R.approved = [sec('entry'), sec('saved'), field('savedMsg'), field('stateBadge'), !document.querySelector('[data-wl-link="edit"]').hidden];
  row(2).click(); var edit = document.querySelector('[data-wl-link="edit"]'); R.draft = [sec('entry'), field('savedMsg').indexOf('chờ BQL duyệt') !== -1, !edit.hidden, /\/readings\/\d+\/edit$/.test(edit.getAttribute('href')), field('csc'), field('cl')];
  R.url = location.search;
  R.layout = { w: window.innerWidth, scroll: document.documentElement.scrollWidth, list: document.querySelector('.wl-list').getBoundingClientRect().bottom, panelTop: el('wl-panel').getBoundingClientRect().top,
    listRight: document.querySelector('.wl-list').getBoundingClientRect().right, panelLeft: el('wl-panel').getBoundingClientRect().left, theadVisible: getComputedStyle(document.querySelector('.wl-table thead')).position !== 'absolute' };
  var pre = document.createElement('pre'); pre.id = '__result'; pre.textContent = JSON.stringify(R); document.body.appendChild(pre);
});
</script>
JS;
        $page = http($jar('kt'), 'GET', $entry . '?meter_id=5')['body'];
        $file = sys_get_temp_dir() . '/wl_' . getmypid() . '.html';
        file_put_contents($file, str_replace('</body>', $harness . '</body>', $page));
        $runs = [];
        foreach ([1440, 900, 500] as $w) {
            $out = (string) shell_exec(escapeshellarg($chrome) . " --headless=new --disable-gpu --no-first-run --hide-scrollbars --window-size={$w},1200 --virtual-time-budget=5000 --dump-dom " . escapeshellarg('file://' . $file) . ' 2>/dev/null');
            preg_match('#<pre id="__result">(.*?)</pre>#s', $out, $m);
            $runs[$w] = json_decode(html_entity_decode($m[1] ?? ''), true);
        }
        @unlink($file);
        $r = $runs[1440];
        if (!is_array($r)) {
            check($G, 'chạy được script kiểm tra trong Chrome headless', false, substr((string) ($out ?? ''), 0, 300));
        } else {
            $js = static fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE);
            check($G, 'Case 2: tìm "cc2305" (không phân biệt hoa thường) → 2 dòng CC-2305, "Hiển thị 2 / 8"', $r['search'] === [5, 6] && $r['count'] === 'Hiển thị 2 / 8 công tơ', $js([$r['search'], $r['count']]));
            check($G, 'Case 3: lọc "Chưa ghi" → 5 công tơ chưa ghi; + lọc Nước → 6, 8', $r['chuaGhi'] === [5, 6, 7, 8, 3] && $r['chuaGhiNuoc'] === [6, 8], $js([$r['chuaGhi'], $r['chuaGhiNuoc']]));
            check($G, 'Không khớp → hiện "Không có công tơ phù hợp bộ lọc."; bộ lọc được chép vào form để giữ sau khi lưu', $r['emptyShown'] === true && $r['keep'] === ['']);
            check($G, 'Case 4: click dòng CC2306-DIEN → dòng được chọn (bỏ chọn dòng cũ, aria-current), panel đổi đúng: mã, căn hộ, CSĐ, meter_id ẩn, form nhập',
                $r['select7'] === [true, false, 'true', 'CC2306-DIEN', 'CHUNGCU-A / CC-2306', '0', '7', '0', true, false, 'Chưa ghi'], $js($r['select7']));
            check($G, 'Case 5+6: nhập CSC 45,5 (CSĐ 0) → CL xem trước 45,5, không lỗi', $r['preview'] === ['45,5', true], $js($r['preview']));
            check($G, 'Đổi công tơ → xoá CSC cũ, CSĐ mới (CC2305-NUOC = 62)', $r['switchReset'] === ['', '62', ''], $js($r['switchReset']));
            check($G, 'Case 7: CSC 50 < CSĐ 62 → lỗi rõ ràng (role=alert, aria-invalid), CL báo không hợp lệ', $r['lower'][0] === false && str_contains($r['lower'][1], 'CSC nhỏ hơn CSĐ (62)') && $r['lower'][2] === 'true' && $r['lower'][3] === 'CSC < CSĐ — không hợp lệ', $js($r['lower']));
            check($G, 'CSC < CSĐ → chặn submit phía trình duyệt (backend vẫn kiểm tra, xem nhóm 2)', $r['blocked'] === true);
            check($G, 'CSC hợp lệ 70 → hết lỗi, cho submit; CSC trống → chặn + "Vui lòng nhập CSC"', $r['allowed'] === true && $r['lowerCleared'] === true && $r['emptyBlocked'][0] === true && str_contains($r['emptyBlocked'][1], 'Vui lòng nhập CSC'), $js([$r['allowed'], $r['emptyBlocked']]));
            check($G, 'Case 10: click công tơ Đã duyệt → ẩn form, "Chỉ số đã được BQL duyệt.", không link Sửa', $r['approved'] === [false, true, 'Chỉ số đã được BQL duyệt.', 'Đã duyệt', false], $js($r['approved']));
            check($G, 'Click công tơ Nháp → ẩn form (BR-08), hiện CSC/CL đã lưu + link Sửa đúng /readings/{id}/edit', $r['draft'] === [false, true, true, true, '232', '16'], $js($r['draft']));
            check($G, 'Chọn dòng không tải lại trang; URL cập nhật ?meter_id= (replaceState)', $r['url'] === '' || str_contains((string) $r['url'], 'meter_id=2'), (string) $r['url']);
            foreach ([1440 => 'Desktop 1440: 2 cột (panel bên phải danh sách)', 900 => 'Tablet 900: 1 cột (danh sách trên, form dưới)', 500 => 'Mobile 500: 1 cột, bảng → thẻ'] as $w => $label) {
                $l = $runs[$w]['layout'] ?? null;
                $ok = is_array($l) && $l['scroll'] <= $l['w'] && ($w === 1440 ? $l['panelLeft'] >= $l['listRight'] - 1 : $l['panelTop'] >= $l['list'] - 1)
                    && ($w === 500 ? $l['theadVisible'] === false : $l['theadVisible'] === true);
                check($G, "Case 12: {$label}, không tràn ngang", $ok, $js($l));
            }
        }
    }
} finally {
    foreach (['kt', 'bql', 'kto'] as $who) {
        @unlink($jar($who));
    }
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
echo "\n{$pass}/{$total} PASS (worklist nhập chỉ số qua {$base}, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
