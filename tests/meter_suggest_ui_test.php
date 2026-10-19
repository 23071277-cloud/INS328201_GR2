<?php

declare(strict_types=1);

/**
 * UX "Thêm công tơ" - tự gợi ý Mã công tơ / Nhãn hiển thị (JS thuần) + backend vẫn là nguồn kiểm tra.
 *   php tests/meter_suggest_ui_test.php
 * 1) Markup thật của /meters/create (Apache, BQL) + script mô phỏng thao tác, chạy trong Chrome headless (--dump-dom).
 *    Không có Chrome -> phần JS SKIPPED (không tính lỗi). Hai căn giả mã "Q<run>A", "Q<run>B" chỉ thêm vào <select> trong trang test (không ghi DB),
 *    mã riêng theo lượt chạy để không trùng mã công tơ người dùng đã tạo trong DB thật.
 * 2) Backend qua Apache: chỉ số âm, mã trùng bị từ chối; tạo hợp lệ rồi xoá (công tơ chưa có chỉ số).
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/real_db_fixture.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Database;

$base = rtrim((string) config('app.url'), '/');
$pdo = Database::connection();
$runId = date('His') . random_int(10, 99);
$jar = sys_get_temp_dir() . "/msug_{$runId}.txt";
$fx = null;

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
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
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

function findChrome(): ?string
{
    foreach ([(string) getenv('CHROME_BIN'), '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $c) {
        if ($c !== '' && is_file($c) && is_executable($c)) {
            return $c;
        }
    }

    return null;
}

try {
    @unlink($jar);
    $page = http($jar, 'GET', $base . '/login');
    $login = http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => 'bql01', 'password' => 'Password@123']);
    check('SETUP', 'Đăng nhập bql01', str_ends_with($login['location'], '/dashboard'));

    // ================================================================ Markup
    $G = 'MARKUP';
    $create = http($jar, 'GET', $base . '/meters/create');
    $html = $create['body'];
    check($G, 'màn Thêm: form có data-meter-suggest + danh sách mã đã có', $create['code'] === 200 && str_contains($html, 'data-meter-suggest') && str_contains($html, 'data-existing-codes="[') && str_contains($html, 'BT1-DIEN'));
    check($G, 'option căn hộ mang data-code', (bool) preg_match('/<option value="\d+" data-code="BT1"/', $html));
    check($G, 'chỉ số khởi tạo mặc định 0', (bool) preg_match('/id="initial_reading"[^>]*value="0"/s', $html));
    check($G, 'helper text mới + aria-describedby cho 3 ô', str_contains($html, 'Hệ thống tự gợi ý theo căn hộ và loại tiện ích; bạn có thể sửa.') && str_contains($html, 'Nếu là công tơ mới/demo, có thể để 0.')
        && str_contains($html, 'aria-describedby="meter_code_hint"') && str_contains($html, 'aria-describedby="display_label_hint"') && str_contains($html, 'aria-describedby="initial_reading_hint"'));
    check($G, 'nhãn <label> vẫn đầy đủ (không chỉ dùng placeholder)', str_contains($html, '<label for="meter_code">Mã công tơ') && str_contains($html, '<label for="display_label">Nhãn hiển thị'));
    $edit = http($jar, 'GET', $base . '/meters/1/edit');
    check($G, 'màn Sửa: KHÔNG bật auto-suggest (không đổi mã đã lưu)', $edit['code'] === 200 && !str_contains($edit['body'], 'data-meter-suggest'));

    // ================================================================ Hành vi JS (Chrome headless)
    $G = 'JS';
    $chrome = findChrome();
    if ($chrome === null) {
        check($G, 'SKIPPED: không tìm thấy Chrome/Chromium (đặt CHROME_BIN) - không kiểm được hành vi JS', true);
    } else {
        $aptA = "Q{$runId}A";
        $aptB = "Q{$runId}B";
        $harness = <<<'JS'
<script>
(function () {
  var R = {};
  function el(id) { return document.getElementById(id); }
  var apt = el('apartment_id'), util = el('utility_type'), usage = el('usage_type'), code = el('meter_code'), label = el('display_label');
  function addOpt(c) { var o = document.createElement('option'); o.value = 'test-' + c; o.setAttribute('data-code', c); o.textContent = c; apt.appendChild(o); return o; }
  function choose(s, v) { s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }
  function type(f, v) { f.value = v; f.dispatchEvent(new Event('input', { bubbles: true })); }
  var a12 = addOpt('__A__'), a13 = addOpt('__B__');
  choose(apt, a12.value); choose(util, 'dien'); R.case1 = [code.value, label.value];
  choose(util, 'nuoc'); R.case2 = [code.value, label.value];
  choose(apt, a13.value); R.case7 = [code.value, label.value];
  type(code, 'CT-A12-01'); choose(usage, 'kinh_doanh'); R.case3_usage = code.value;
  choose(util, 'dien'); R.case3_util = [code.value, label.value];
  type(label, 'Nhãn riêng'); choose(util, 'nuoc'); R.case4 = [code.value, label.value];
  type(code, ''); type(label, ''); choose(util, 'dien'); R.cleared = [code.value, label.value];
  R.case5 = el('initial_reading').value;
  var bt1 = Array.prototype.find.call(apt.options, function (o) { return o.getAttribute('data-code') === 'BT1'; });
  choose(apt, bt1.value); choose(util, 'dien'); R.duplicate = code.value;
  R.pure = [MeterSuggest.code('A12', 'dien', []), MeterSuggest.code('A12', 'nuoc', []), MeterSuggest.code('CC-2305', 'dien', []), MeterSuggest.label('A12', 'dien'), MeterSuggest.label('A12', 'nuoc'),
    MeterSuggest.code('', 'dien', []), MeterSuggest.code('A12', '', []), MeterSuggest.code('A12', 'dien', ['A12-DIEN', 'a12-dien-2']), MeterSuggest.code('X'.repeat(40), 'nuoc', []).length];
  R.editable = [code.readOnly, code.disabled, label.readOnly, label.disabled];
  var pre = document.createElement('pre'); pre.id = '__result'; pre.textContent = JSON.stringify(R); document.body.appendChild(pre);
})();
</script>
JS;
        $file = sys_get_temp_dir() . "/msug_{$runId}.html";
        file_put_contents($file, str_replace('</body>', str_replace(['__A__', '__B__'], [$aptA, $aptB], $harness) . '</body>', $html));
        $out = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-first-run --virtual-time-budget=5000 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        @unlink($file);
        preg_match('#<pre id="__result">(.*?)</pre>#s', $out, $m);
        $r = json_decode(html_entity_decode($m[1] ?? ''), true);
        if (!is_array($r)) {
            check($G, 'chạy được script kiểm tra trong Chrome headless', false, substr($out, 0, 300));
        } else {
            check($G, 'Case 1: căn A + Điện → <A>-DIEN / "Điện căn <A>"', $r['case1'] === ["{$aptA}-DIEN", "Điện căn {$aptA}"], json_encode($r['case1'], JSON_UNESCAPED_UNICODE));
            check($G, 'Case 2: căn A + Nước → <A>-NUOC / "Nước căn <A>"', $r['case2'] === ["{$aptA}-NUOC", "Nước căn {$aptA}"], json_encode($r['case2'], JSON_UNESCAPED_UNICODE));
            check($G, 'Đổi sang căn B (chưa sửa tay) → <B>-NUOC / "Nước căn <B>"', $r['case7'] === ["{$aptB}-NUOC", "Nước căn {$aptB}"], json_encode($r['case7'], JSON_UNESCAPED_UNICODE));
            check($G, 'Case 3: sửa mã tay "CT-A12-01" → đổi Loại sử dụng: giữ nguyên', $r['case3_usage'] === 'CT-A12-01');
            check($G, 'Case 3b: đổi Loại tiện ích: mã tay giữ nguyên, nhãn (chưa sửa) cập nhật', $r['case3_util'] === ['CT-A12-01', "Điện căn {$aptB}"], json_encode($r['case3_util'], JSON_UNESCAPED_UNICODE));
            check($G, 'Case 4: sửa nhãn tay → đổi tiện ích: nhãn giữ nguyên', $r['case4'] === ['CT-A12-01', 'Nhãn riêng'], json_encode($r['case4'], JSON_UNESCAPED_UNICODE));
            check($G, 'xoá trống ô → quay lại tự gợi ý', $r['cleared'] === ["{$aptB}-DIEN", "Điện căn {$aptB}"], json_encode($r['cleared'], JSON_UNESCAPED_UNICODE));
            check($G, 'Case 5: chỉ số khởi tạo mặc định 0', $r['case5'] === '0');
            check($G, 'mã gợi ý đã tồn tại (BT1-DIEN) → gợi ý BT1-DIEN-2', $r['duplicate'] === 'BT1-DIEN-2', (string) $r['duplicate']);
            check($G, 'hàm gợi ý: A12-DIEN, A12-NUOC, CC-2305 → CC2305-DIEN (convention seed), nhãn Điện/Nước căn; thiếu căn / tiện ích → rỗng; tránh mã đã có (không phân biệt hoa thường); ≤ 30 ký tự',
                $r['pure'] === ['A12-DIEN', 'A12-NUOC', 'CC2305-DIEN', 'Điện căn A12', 'Nước căn A12', '', '', 'A12-DIEN-3', 30], json_encode($r['pure'], JSON_UNESCAPED_UNICODE));
            check($G, 'mã và nhãn không readonly / disabled (người dùng sửa được)', $r['editable'] === [false, false, false, false]);
        }
    }

    // ================================================================ Backend vẫn là nguồn kiểm tra
    $G = 'BACKEND';
    // căn FIXTURE riêng (không thêm công tơ vào căn của người dùng); mã trùng thử bằng mã công tơ fixture
    $fx = e2e_fixture_create($pdo, $runId);
    $fxApt = $fx['apt']['BT1'];
    $dupCode = $fx['meter_code'][1];
    // $over + mặc định: khoá trong $over được ưu tiên (toán tử + của mảng PHP giữ khoá vế trái)
    $post = static fn (array $over) => $over + ['csrf_token' => csrfFrom($create['body']), 'apartment_id' => (string) $fxApt, 'meter_code' => "UXT{$runId}-DIEN", 'utility_type' => 'dien',
        'usage_type' => 'sinh_hoat', 'display_label' => 'Điện căn BT1', 'initial_reading' => '0'];
    $fxMeters = implode(',', $fx['meter']);
    $count = static fn () => (int) $pdo->query("SELECT COUNT(*) FROM meters WHERE apartment_id = {$fxApt} AND id NOT IN ({$fxMeters})")->fetchColumn();
    http($jar, 'POST', $base . '/meters', $post(['initial_reading' => '-1']));
    $form = http($jar, 'GET', $base . '/meters/create')['body'];
    check($G, 'Case 6: chỉ số khởi tạo âm → backend từ chối', $count() === 0 && str_contains($form, 'Chỉ số khởi tạo phải là số ≥ 0'), 'count=' . $count() . ' err=' . (preg_match('/form-error">([^<]*)/', $form, $em) ? $em[1] : 'none'));
    http($jar, 'POST', $base . '/meters', $post(['meter_code' => $dupCode]));
    $form = http($jar, 'GET', $base . '/meters/create')['body'];
    check($G, "Case 7: mã trùng ({$dupCode}) → backend từ chối \"đã tồn tại\"", $count() === 0 && str_contains($form, 'đã tồn tại'));
    http($jar, 'POST', $base . '/meters', $post(['utility_type' => 'gas', 'meter_code' => "UXT{$runId}-GAS"]));
    check($G, 'loại tiện ích ngoài danh mục → backend từ chối', $count() === 0);
    http($jar, 'POST', $base . '/meters', $post(['meter_code' => '']));
    check($G, 'mã rỗng (bỏ qua gợi ý) → backend từ chối', $count() === 0);
    $r = http($jar, 'POST', $base . '/meters', $post([]));
    $saved = $pdo->query("SELECT meter_code, display_label, initial_reading FROM meters WHERE apartment_id = {$fxApt} AND id NOT IN ({$fxMeters})")->fetch();
    check($G, 'giá trị gợi ý hợp lệ → tạo được, lưu đúng mã / nhãn / chỉ số 0', $r['code'] === 302 && $saved !== false && $saved['meter_code'] === "UXT{$runId}-DIEN"
        && $saved['display_label'] === 'Điện căn BT1' && $saved['initial_reading'] === '0.00', json_encode($saved, JSON_UNESCAPED_UNICODE));
} finally {
    // chỉ công tơ do lượt chạy tạo (mã UXT<runId>) + fixture - không đụng công tơ người dùng
    $pdo->prepare('DELETE FROM meters WHERE meter_code LIKE ?')->execute(["UXT{$runId}%"]);
    $left = (int) $pdo->query("SELECT COUNT(*) FROM meters WHERE meter_code LIKE 'UXT{$runId}%'")->fetchColumn();
    if ($fx !== null) {
        e2e_cleanup($pdo, $fx);
        $left += e2e_leftovers($pdo, $fx);
    }
    @unlink($jar);
    check('CLEANUP', 'Đã xoá công tơ / fixture E2E của lượt chạy (audit giữ lại)', $left === 0, "còn {$left}");
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
echo "\n{$pass}/{$total} PASS (UI + HTTP qua {$base}, run {$runId})\n";
exit($pass === $total ? 0 : 1);
